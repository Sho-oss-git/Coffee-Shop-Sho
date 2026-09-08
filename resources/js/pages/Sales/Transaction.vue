<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    ArrowUpRight,
    Banknote,
    Boxes,
    ClipboardList,
    Package,
    ShoppingBag,
    Sparkles,
    TrendingUp,
    Wallet,
} from 'lucide-vue-next';

interface Summary {
    total_sales: number;
    transaction_count: number;
    items_sold: number;
    average_sale: number;
    total_cogs: number;
    gross_profit: number;
}

interface RecentTransaction {
    id: number;
    time: string;
    cashier: string | null;
    total: number;
    status: string;
    payment_method: 'cash' | 'gcash' | null;
}

interface TopProduct {
    name: string;
    quantity: number;
}

interface CashierSales {
    cashier: string;
    transaction_count: number;
    sales: number;
}

interface PaymentMethodBreakdown {
    method: string;
    label: string;
    count: number;
    total: number;
}

const props = defineProps<{
    summary: Summary;
    recentTransactions: RecentTransaction[];
    topProducts: TopProduct[];
    salesByCashier: CashierSales[];
    salesByPaymentMethod: PaymentMethodBreakdown[];
    date: string;
}>();

function money(value: number | string) {
    return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDateLabel(value: string) {
    if (!value) {
        return 'Sales';
    }

    if (value === 'all') {
        return 'All time';
    }

    if (/^\d{4}$/.test(value)) {
        return `${value} Sales`;
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return 'Sales';
    }

    return parsed.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

function paymentLabel(method: string | null) {
    return method === 'gcash' ? 'GCash' : 'Cash';
}

// ---- Highlight banner: one honest, data-backed line ----
const highlight = computed(() => {
    const parts: string[] = [];
    parts.push(`${props.summary.transaction_count} transaction${props.summary.transaction_count === 1 ? '' : 's'} recorded so far`);
    if (props.topProducts.length) {
        parts.push(`top seller: ${props.topProducts[0].name}`);
    }
    return parts.join(' · ');
});

// ---- Stat cards, styled like the dashboard's icon metric cards ----
const metrics = computed(() => [
    { label: 'Year total sales', value: money(props.summary.total_sales), icon: Banknote, tone: 'text-emerald-600', surface: 'bg-emerald-500/10' },
    { label: 'Transactions', value: props.summary.transaction_count, icon: ShoppingBag, tone: 'text-sky-600', surface: 'bg-sky-500/10' },
    { label: 'Items sold', value: props.summary.items_sold, icon: Package, tone: 'text-amber-600', surface: 'bg-amber-500/10' },
    { label: 'Average sale', value: money(props.summary.average_sale), icon: TrendingUp, tone: 'text-violet-600', surface: 'bg-violet-500/10' },
    { label: 'COGS', value: money(props.summary.total_cogs), icon: Boxes, tone: 'text-orange-600', surface: 'bg-orange-500/10' },
    { label: 'Gross profit', value: money(props.summary.gross_profit), icon: TrendingUp, tone: 'text-teal-600', surface: 'bg-teal-500/10' },
]);

// ---- Payment methods donut, same visual language as the dashboard ----
const totalPaymentSales = computed(() => props.salesByPaymentMethod.reduce((sum, p) => sum + p.total, 0));
const totalPaymentCount = computed(() => props.salesByPaymentMethod.reduce((sum, p) => sum + p.count, 0));

const cashEntry = computed(() => props.salesByPaymentMethod.find((p) => p.method !== 'gcash'));
const gcashEntry = computed(() => props.salesByPaymentMethod.find((p) => p.method === 'gcash'));

const cashPercent = computed(() =>
    totalPaymentSales.value > 0 ? Math.round(((cashEntry.value?.total ?? 0) / totalPaymentSales.value) * 100) : 0,
);
const gcashPercent = computed(() => (totalPaymentCount.value ? 100 - cashPercent.value : 0));

const donutGradient = computed(() => {
    if (!totalPaymentSales.value) return 'conic-gradient(var(--muted) 0% 100%)';
    return `conic-gradient(#059669 0% ${cashPercent.value}%, #f59e0b ${cashPercent.value}% 100%)`;
});
</script>

<template>
    <Head title="Sale Transaction" />

    <AppLayout>
        <div class="flex min-w-0 flex-col gap-6 bg-background p-3 sm:p-4 lg:p-6">
            <!-- Header -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-foreground/70">{{ formatDateLabel(date) }}</p>
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl text-foreground">Sales Monitoring</h1>
                    <p class="text-sm text-foreground/60">Live view of this year's sales.</p>
                </div>
            </div>

            <!-- Highlight banner -->
            <div class="flex flex-col gap-4 rounded-xl bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-950 p-4 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10">
                        <Sparkles class="h-5 w-5 text-amber-300" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold">Yearly sales highlight</p>
                        <p class="text-sm text-emerald-50/85">{{ highlight }}</p>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
                <div v-for="metric in metrics" :key="metric.label" class="flex flex-col justify-between rounded-xl border bg-background p-4 shadow-sm">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg" :class="metric.surface">
                        <component :is="metric.icon" class="h-5 w-5" :class="metric.tone" />
                    </span>
                    <div class="mt-3">
                        <p class="text-xl font-semibold text-foreground">{{ metric.value }}</p>
                        <p class="text-xs text-foreground/60">{{ metric.label }}</p>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <!-- Recent transactions -->
                <section class="min-w-0 overflow-hidden rounded-xl border bg-background shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                        <div>
                            <h2 class="font-semibold text-foreground">Recent Transactions</h2>
                            <p class="text-xs text-foreground/60">Latest sales this year</p>
                        </div>
                        <!-- <Link :href="route('sales-history')" class="text-sm text-primary hover:underline"> View All → </Link> -->
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[620px] text-sm">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="p-2 text-left text-foreground/70">Time</th>
                                    <th class="p-2 text-left text-foreground/70">Transaction</th>
                                    <th class="p-2 text-left text-foreground/70">Cashier</th>
                                    <th class="p-2 text-left text-foreground/70">Payment</th>
                                    <th class="p-2 text-right text-foreground/70">Total</th>
                                    <th class="p-2 text-left text-foreground/70">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="t in recentTransactions" :key="t.id" class="border-t">
                                    <td class="p-2 text-foreground">{{ t.time }}</td>
                                    <td class="p-2 text-foreground">#{{ t.id }}</td>
                                    <td class="p-2 text-foreground">{{ t.cashier }}</td>
                                    <td class="p-2">
                                        <span class="inline-flex items-center gap-1 text-xs">
                                            <Wallet class="h-3.5 w-3.5" :class="t.payment_method === 'gcash' ? 'text-amber-500' : 'text-emerald-600'" />
                                            <span class="text-foreground">{{ paymentLabel(t.payment_method) }}</span>
                                        </span>
                                    </td>
                                    <td class="p-2 text-right text-foreground">{{ money(t.total) }}</td>
                                    <td class="p-2 capitalize text-foreground">{{ t.status }}</td>
                                </tr>
                                <tr v-if="!recentTransactions.length">
                                    <td colspan="6" class="p-4 text-center text-foreground/60">No completed transactions yet</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Payment methods -->
                <section class="flex flex-col gap-4 rounded-xl border bg-background p-4 shadow-sm">
                    <div>
                        <h2 class="font-semibold text-foreground">Payment methods</h2>
                        <p class="text-xs text-foreground/60">Sales by payment method</p>
                    </div>

                    <div v-if="salesByPaymentMethod.length" class="flex flex-col items-center gap-4 py-2">
                        <div class="relative h-36 w-36 rounded-full" :style="{ background: donutGradient }">
                            <div class="absolute inset-[16px] flex flex-col items-center justify-center rounded-full bg-background">
                                <span class="text-xl font-bold text-foreground">{{ totalPaymentCount }}</span>
                                <span class="text-[11px] text-foreground/60">transactions</span>
                            </div>
                        </div>
                        <div class="w-full space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span><span class="text-foreground">Cash</span></span>
                                <span class="font-medium text-foreground">{{ cashEntry?.count ?? 0 }} ({{ cashPercent }}%) · {{ money(cashEntry?.total ?? 0) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span><span class="text-foreground">GCash</span></span>
                                <span class="font-medium text-foreground">{{ gcashEntry?.count ?? 0 }} ({{ gcashPercent }}%) · {{ money(gcashEntry?.total ?? 0) }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="py-8 text-center text-sm text-foreground/60">No sales recorded yet this year</p>
                </section>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Top products -->
                <section class="rounded-xl border bg-background shadow-sm">
                    <div class="border-b p-4">
                        <h2 class="font-semibold text-foreground">Top Products</h2>
                        <p class="text-xs text-foreground/60">Best sellers this year</p>
                    </div>
                    <ol class="divide-y">
                        <li v-for="(product, index) in topProducts" :key="product.name" class="flex items-center justify-between p-3 text-sm">
                            <span class="flex items-center gap-3">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-500/10 text-xs font-semibold text-sky-600">
                                    {{ index + 1 }}
                                </span>
                                <span class="text-foreground">{{ product.name }}</span>
                            </span>
                            <span class="font-medium text-foreground">{{ product.quantity }} sold</span>
                        </li>
                        <li v-if="!topProducts.length" class="p-4 text-center text-sm text-foreground/60">No sales recorded yet this year</li>
                    </ol>
                </section>

                <!-- Sales by cashier -->
                <section class="rounded-xl border bg-background shadow-sm">
                    <div class="border-b p-4">
                        <h2 class="font-semibold text-foreground">Sales by Cashier</h2>
                        <p class="text-xs text-foreground/60">Performance per cashier this year</p>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="p-2 text-left text-foreground/70">Cashier</th>
                                <th class="p-2 text-right text-foreground/70">Transactions</th>
                                <th class="p-2 text-right text-foreground/70">Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in salesByCashier" :key="c.cashier" class="border-t">
                                <td class="p-2 text-foreground">{{ c.cashier }}</td>
                                <td class="p-2 text-right text-foreground">{{ c.transaction_count }}</td>
                                <td class="p-2 text-right text-foreground">{{ money(c.sales) }}</td>
                            </tr>
                            <tr v-if="!salesByCashier.length">
                                <td colspan="3" class="p-4 text-center text-foreground/60">No sales yet this year</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AppLayout>
</template>