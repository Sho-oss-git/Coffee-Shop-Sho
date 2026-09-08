<script setup lang="ts">
import { usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import DailyMonthlyYearly from '@/components/DailyMonthlyYearly.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head } from '@inertiajs/vue3';
import ClockStatusToggle from '@/components/ClockStatusToggle.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { ArrowUpRight, Banknote, Boxes, ClipboardList, Package, ShoppingBag, Sparkles, TrendingUp } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface TrendPoint {
    hour: number;
    label: string;
    sales: number;
}

type Period = 'daily' | 'monthly' | 'yearly';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const props = withDefaults(
    defineProps<{
        summary: { sales: number; transactions: number; items: number; average_sale: number };
        recentTransactions: { id: number; time: string; cashier: string; total: number; payment_method: string }[];
        pendingRequests: number;
        productCount: number;
        date: string;
        salesTrend?: TrendPoint[];
        filters?: { period: Period; date: string };
    }>(),
    {
        salesTrend: () => [],
        filters: () => ({ period: 'daily', date: new Date().toISOString().slice(0, 10) }),
    },
);

// Drives the Sales trend card below — changing either reloads the dashboard
// with fresh summary/highlight/trend data for that period.
const period = ref(props.filters.period);
const trendDate = ref(props.filters.date);

function applyTrendFilters() {
    router.get(
        window.location.pathname,
        { period: period.value, date: trendDate.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const page = usePage<SharedData>();
const currentTime = ref('');
let clockTimer: ReturnType<typeof setInterval> | undefined;

function updateClock() {
    currentTime.value = new Date().toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });
}

onMounted(() => {
    updateClock();
    clockTimer = setInterval(updateClock, 1000);
});

onUnmounted(() => {
    if (clockTimer) clearInterval(clockTimer);
});

function money(value: number) {
    return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// Greeting based on time of day
const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
});

const periodNoun = computed(() => {
    if (period.value === 'yearly') return 'this year';
    if (period.value === 'monthly') return 'this month';
    return 'today';
});

const periodPossessive = computed(() => {
    if (period.value === 'yearly') return "This year's";
    if (period.value === 'monthly') return "This month's";
    return "Today's";
});

// A single, honest, data-backed line — no fabricated "AI" claims.
const highlight = computed(() => {
    const parts: string[] = [];
    if (props.pendingRequests > 0) {
        parts.push(`${props.pendingRequests} action request${props.pendingRequests === 1 ? '' : 's'} waiting for review`);
    } else {
        parts.push('No action requests pending');
    }
    parts.push(`${props.productCount} product${props.productCount === 1 ? '' : 's'} currently listed for sale`);
    return parts.join(' · ');
});

// Stat cards
const metrics = computed(() => [
    { label: `${periodPossessive.value} sales`, value: money(props.summary.sales), icon: Banknote, tone: 'text-emerald-600', surface: 'bg-emerald-500/10' },
    { label: 'Transactions', value: props.summary.transactions, icon: ShoppingBag, tone: 'text-sky-600', surface: 'bg-sky-500/10' },
    { label: 'Items sold', value: props.summary.items, icon: Package, tone: 'text-amber-600', surface: 'bg-amber-500/10' },
    { label: 'Average sale', value: money(props.summary.average_sale), icon: TrendingUp, tone: 'text-violet-600', surface: 'bg-violet-500/10' },
    { label: 'Action requests', value: props.pendingRequests, icon: ClipboardList, tone: 'text-orange-600', surface: 'bg-orange-500/10' },
    { label: 'Products listed', value: props.productCount, icon: Boxes, tone: 'text-teal-600', surface: 'bg-teal-500/10' },
]);

// Payment method breakdown, derived from real transaction data
const cashCount = computed(() => props.recentTransactions.filter((t) => t.payment_method !== 'gcash').length);
const gcashCount = computed(() => props.recentTransactions.filter((t) => t.payment_method === 'gcash').length);
const totalCount = computed(() => props.recentTransactions.length);
const cashPercent = computed(() => (totalCount.value ? Math.round((cashCount.value / totalCount.value) * 100) : 0));
const gcashPercent = computed(() => (totalCount.value ? 100 - cashPercent.value : 0));
const donutGradient = computed(() => {
    if (!totalCount.value) return 'conic-gradient(var(--muted) 0% 100%)';
    return `conic-gradient(#059669 0% ${cashPercent.value}%, #f59e0b ${cashPercent.value}% 100%)`;
});

// ---- Sales trend chart (same lightweight inline-SVG approach as the Sales Monitoring page) ----
// For 'daily' the backend supplies hourly points; for 'monthly' day-of-month points;
// for 'yearly' month-of-year points. The chart itself is period-agnostic — it just
// plots whatever points salesTrend contains, using `label` for the axis ticks.
const chartWidth = 760;
const chartHeight = 200;
const paddingX = 32;
const paddingY = 24;

const maxSale = computed(() => Math.max(...props.salesTrend.map((p) => p.sales), 1));

const trendPoints = computed(() => {
    const n = props.salesTrend.length;
    if (n <= 1) return [];
    const usableWidth = chartWidth - paddingX * 2;
    const usableHeight = chartHeight - paddingY * 2;

    return props.salesTrend.map((p, i) => {
        const x = paddingX + (i / (n - 1)) * usableWidth;
        const y = paddingY + usableHeight - (p.sales / maxSale.value) * usableHeight;
        return { x, y, ...p };
    });
});

const linePath = computed(() =>
    trendPoints.value.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' '),
);

const areaPath = computed(() => {
    if (!trendPoints.value.length) return '';
    const last = trendPoints.value[trendPoints.value.length - 1];
    const first = trendPoints.value[0];
    return `${linePath.value} L ${last.x.toFixed(1)} ${chartHeight - paddingY} L ${first.x.toFixed(1)} ${chartHeight - paddingY} Z`;
});

// Thin out axis labels more aggressively for monthly/yearly views, which have more points
const tickStride = computed(() => {
    if (period.value === 'monthly') return 4; // e.g. every 4th day
    if (period.value === 'yearly') return 1; // every month
    return 2; // every 2nd hour
});
const visibleTicks = computed(() => trendPoints.value.filter((_, i) => i % tickStride.value === 0));

const peakHour = computed(() => {
    if (!props.salesTrend.length) return null;
    return props.salesTrend.reduce((best, p) => (p.sales > best.sales ? p : best), props.salesTrend[0]);
});

const trendTitle = computed(() => {
    if (period.value === 'yearly') return 'Sales trend this year';
    if (period.value === 'monthly') return 'Sales trend this month';
    return 'Sales trend today';
});

const trendSubtitle = computed(() => {
    if (period.value === 'yearly') return 'Monthly sales for the year';
    if (period.value === 'monthly') return 'Daily sales for the month';
    return 'Hourly sales from open to now';
});

const peakLabel = computed(() => {
    if (period.value === 'yearly') return 'Peak month';
    if (period.value === 'monthly') return 'Peak day';
    return 'Peak hour';
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex min-w-0 flex-col gap-6 bg-background p-3 sm:p-4 lg:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-foreground/70">{{ date }}</p>
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl text-foreground">
                        {{ greeting }}, {{ page.props.auth.user.name }} <span aria-hidden="true">👋</span>
                    </h1>
                    <p class="text-sm text-foreground/60">Here's what's happening at the counter {{ periodNoun }}.</p>
                </div>
                <div class="flex w-full flex-wrap items-center justify-between gap-3 sm:w-auto sm:justify-end">
                    <span class="text-sm font-semibold tabular-nums text-foreground/70" aria-live="polite">{{ currentTime }}</span>

                    <!-- <a v-if="page.props.auth.user.role !== 'cashier'" href="/reports/sales" class="inline-flex w-full items-center justify-center gap-2 rounded-md border bg-background px-3 py-2 text-sm font-medium text-foreground shadow-sm hover:bg-muted sm:w-auto">
                        View sales report <ArrowUpRight class="h-4 w-4" />
                    </a> -->
                </div>
            </div>

            <div
                v-if="page.props.auth.user.role !== 'admin'"
                class="flex items-center justify-between rounded-xl border border-border bg-background p-4 shadow-sm"
            >
                <div>
                    <p class="text-sm text-foreground/70">Your status</p>
                    <StatusBadge :status="page.props.auth.user.status" />
                </div>
                <ClockStatusToggle :status="page.props.auth.user.status" />
            </div>

            <!-- Highlight banner -->
            <div class="flex flex-col gap-4 rounded-xl bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-950 p-4 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10">
                        <Sparkles class="h-5 w-5 text-amber-300" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold">{{ periodPossessive }} highlight</p>
                        <p class="text-sm text-emerald-50/85">{{ highlight }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="/action-requests" class="inline-flex items-center justify-center rounded-md bg-white/10 px-3 py-2 text-sm font-medium hover:bg-white/20">
                        Review requests
                    </a>
                    <a v-if="page.props.auth.user.role !== 'cashier'" href="/reports/sales" class="inline-flex items-center justify-center rounded-md bg-amber-400 px-3 py-2 text-sm font-semibold text-emerald-950 hover:bg-amber-300">
                         View sales report
                    </a>
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
              <section class="rounded-xl border bg-background shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b p-4">
        <div>
            <h2 class="font-semibold text-foreground">{{ trendTitle }}</h2>
            <p class="text-xs text-foreground/60">{{ trendSubtitle }}</p>
        </div>

        <div class="flex items-center gap-3">
            <p v-if="peakHour && peakHour.sales > 0" class="text-xs text-foreground/60">
                {{ peakLabel }}:
                <span class="font-medium text-foreground">{{ peakHour.label }}</span>
                ({{ money(peakHour.sales) }})
            </p>

            <div class="inline-flex items-center rounded-lg border bg-muted/40 p-0.5">
                <button
                    v-for="opt in [{ value: 'daily', label: 'Day' }, { value: 'monthly', label: 'Month' }, { value: 'yearly', label: 'Year' }]"
                    :key="opt.value"
                    type="button"
                    @click="period = opt.value; applyTrendFilters()"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="period === opt.value
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'"
                >
                    {{ opt.label }}
                </button>
            </div>
        </div>
    </div>

    <div class="p-4">
        <svg
            v-if="salesTrend.length > 1"
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            class="h-auto w-full"
            preserveAspectRatio="xMidYMid meet"
        >
            <line
                :x1="paddingX"
                :y1="chartHeight - paddingY"
                :x2="chartWidth - paddingX"
                :y2="chartHeight - paddingY"
                stroke="currentColor"
                class="text-border"
                stroke-width="1"
            />

            <path :d="areaPath" fill="currentColor" class="text-emerald-600/10" />
            <path :d="linePath" fill="none" stroke="currentColor" class="text-emerald-600" stroke-width="2" />

            <circle
                v-for="p in trendPoints"
                :key="p.hour"
                :cx="p.x"
                :cy="p.y"
                r="3"
                fill="currentColor"
                class="text-emerald-600"
            >
                <title>{{ p.label }} — {{ money(p.sales) }}</title>
            </circle>

            <text
                v-for="p in visibleTicks"
                :key="`label-${p.hour}`"
                :x="p.x"
                :y="chartHeight - 4"
                text-anchor="middle"
                class="fill-muted-foreground"
                font-size="10"
            >
                {{ p.label }}
            </text>
        </svg>
        <p v-else class="py-8 text-center text-sm text-foreground/60">Not enough data yet to show a trend.</p>
    </div>
</section>

                <section class="flex flex-col gap-4 rounded-xl border bg-background p-4 shadow-sm">
                    <div><h2 class="font-semibold text-foreground">Payment methods</h2><p class="text-xs text-foreground/60">{{ periodPossessive }} transactions by method</p></div>

                    <div class="flex flex-col items-center gap-4 py-2">
                        <div class="relative h-36 w-36 rounded-full" :style="{ background: donutGradient }">
                            <div class="absolute inset-[16px] flex flex-col items-center justify-center rounded-full bg-background">
                                <span class="text-xl font-bold text-foreground">{{ totalCount }}</span>
                                <span class="text-[11px] text-foreground/60">transactions</span>
                            </div>
                        </div>
                        <div class="w-full space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span><span class="text-foreground">Cash</span></span>
                                <span class="font-medium text-foreground">{{ cashCount }} ({{ cashPercent }}%)</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span><span class="text-foreground">GCash</span></span>
                                <span class="font-medium text-foreground">{{ gcashCount }} ({{ gcashPercent }}%)</span>
                            </div>
                        </div>
                    </div>

                    <a :href="page.props.auth.user.role === 'cashier' ? route('cashier.products.index') : route('products.index')" class="mt-auto flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50">
                        <span class="flex items-center gap-3"><Package class="h-4 w-4 text-sky-600" /><span class="text-sm font-medium text-foreground">Available products</span></span>
                        <span class="text-sm font-semibold text-foreground">{{ productCount }}</span>
                    </a>
                </section>
            </div>
        </div>
    </AppLayout>
</template>