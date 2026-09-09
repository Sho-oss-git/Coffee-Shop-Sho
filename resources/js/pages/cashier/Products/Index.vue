<script setup lang="ts">
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ImageOff, Loader2, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import OrderOptions from './OrderOptions.vue';
import OrderSummary from './OrderSummary.vue';
import TransactionComplete from './TransactionComplete.vue';
import TransactionReceipt from './TransactionReceipt.vue';

// Keep the page types local so it remains compatible with the child components'
// default-only exports.
interface CartItem {
    id: number;
    name: string;
    price: number;
    qty: number;
}

interface OrderDetails {
    orderType: 'dine_in' | 'take_out';
    customerName: string;
    notes: string;
}

// The child component can emit either cash or GCash payment data.
interface PaymentPayload {
    method: 'cash' | 'gcash';
    amountReceived: number;
    amountPaid: number;
    referenceNumber: string;
    proofImage: File | null;
}

// Shape of the `transaction` flash set by TransactionController@store after
// a successful checkout. Drives the TransactionComplete receipt modal.
interface CompletedTransaction {
    id: number;
    order_number: number;
    transaction_no: string;
    payment_method: 'cash' | 'gcash';
    total: number;
    amount_received: number;
    change: number;
    gcash_reference_number: string | null;
    cashier: string;
    created_at: string;
    user: { name: string } | null;
    items: {
        product_name: string;
        quantity: number;
        price: number;
        subtotal: number;
    }[];
}

// These components do not expose their instance methods in their public
// component typings, although the page uses those methods at runtime.
const TypedOrderSummary = OrderSummary as any;
const TypedOrderOptions = OrderOptions as any;

interface CashierProduct {
    id: number;
    name: string;
    category: string;
    price: number;
    image_url: string | null;
    is_available: boolean;
    stock_left: number | null;
}

interface CategoryType {
    id: number;
    name: string;
}

const props = defineProps<{
    products: CashierProduct[];
    categories: CategoryType[];
    filters: { search?: string; category?: string };
    next_order_number: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Products', href: route('cashier.products.index') }];

/* Search / Filters */
const search = ref(props.filters.search ?? '');
const categoryFilter = ref(props.filters.category ?? '');
const isFiltering = ref(false);
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

function applyFilters() {
    isFiltering.value = true;
    router.get(
        route('cashier.products.index'),
        {
            search: search.value || undefined,
            category: categoryFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, onFinish: () => (isFiltering.value = false) },
    );
}

watch(search, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 350);
});
watch(categoryFilter, applyFilters);

const hasActiveFilters = computed(() => !!(search.value || categoryFilter.value));

function clearFilters() {
    if (searchDebounce) clearTimeout(searchDebounce);
    search.value = '';
    categoryFilter.value = '';
}

/* Group products by category, with a trailing "Out of stock" section. */
interface ProductSection {
    key: string;
    label: string;
    products: CashierProduct[];
}

const sections = computed<ProductSection[]>(() => {
    const inStock = props.products.filter((p) => p.is_available);
    const outOfStock = props.products.filter((p) => !p.is_available);

    const byCategory = new Map<string, CashierProduct[]>();
    for (const product of inStock) {
        const list = byCategory.get(product.category) ?? [];
        list.push(product);
        byCategory.set(product.category, list);
    }

    const result: ProductSection[] = Array.from(byCategory.entries())
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([category, list]) => ({ key: category, label: category, products: list }));

    if (outOfStock.length > 0) {
        result.push({ key: '__out_of_stock', label: 'Out of stock', products: outOfStock });
    }

    return result;
});

function categoryInitial(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
}

/* --- Order Summary / Cart --- */
const cart = ref<CartItem[]>([]);

function addToCart(product: CashierProduct) {
    if (!product.is_available) return;

    const existing = cart.value.find((item) => item.id === product.id);
    if (existing) {
        existing.qty += 1;
        return;
    }

    cart.value.push({ id: product.id, name: product.name, price: Number(product.price), qty: 1 });
}

function incrementQty(id: number) {
    const item = cart.value.find((i) => i.id === id);
    if (item) item.qty += 1;
}

function decrementQty(id: number) {
    const item = cart.value.find((i) => i.id === id);
    if (!item) return;
    if (item.qty <= 1) {
        cart.value = cart.value.filter((i) => i.id !== id);
        return;
    }
    item.qty -= 1;
}

function removeFromCart(id: number) {
    cart.value = cart.value.filter((i) => i.id !== id);
}

function clearCart() {
    cart.value = [];
}

/* --- Order Options (dine-in/take-out, order #, customer name, notes) --- */
const orderOptionsRef = ref<any>(null);

const orderDetails = ref<OrderDetails | null>(null);

function openOrderOptions() {
    orderOptionsRef.value?.open(orderDetails.value);
}

function handleOrderOptionsConfirm(details: OrderDetails) {
    orderDetails.value = details;
}

/* --- Pay / Transaction submit --- */
const orderSummaryRef = ref<any>(null);

const payForm = useForm({
    items: [] as { product_id: number; quantity: number }[],
    amount_received: 0,
    order_type: null as 'dine_in' | 'take_out' | null,
    customer_name: '',
    notes: '',
    payment_method: 'cash' as 'cash' | 'gcash',
    gcash_reference_number: '',
    gcash_proof: null as File | null,
});

const payError = ref<string | null>(null);

function handlePay(payload: PaymentPayload) {
    payError.value = null;

    payForm.items = cart.value.map((item) => ({ product_id: item.id, quantity: item.qty }));
    payForm.order_type = orderDetails.value?.orderType ?? null;
    payForm.customer_name = orderDetails.value?.customerName ?? '';
    payForm.notes = orderDetails.value?.notes ?? '';
    payForm.payment_method = payload.method;

    if (payload.method === 'cash') {
        payForm.amount_received = payload.amountReceived;
        payForm.gcash_reference_number = '';
        payForm.gcash_proof = null;
    } else {
        payForm.amount_received = payload.amountPaid;
        payForm.gcash_reference_number = payload.referenceNumber;
        payForm.gcash_proof = payload.proofImage;
    }

    // forceFormData because gcash_proof may be a File — Inertia needs
    // multipart/form-data for that, and it degrades fine for cash too.
    payForm.post(route('cashier.transactions.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            clearCart();
            // Reset for the next customer — a fresh order needs its own
            // dine-in/take-out choice, not the previous customer's, and the
            // payment fields (amount typed, GCash screenshot) shouldn't
            // carry over either. The `transaction` flash that came back
            // with this response is picked up by the watcher below, which
            // opens the TransactionComplete receipt modal.
            orderDetails.value = null;
            orderSummaryRef.value?.resetPayment();
        },
        onError: (errors) => {
            payError.value =
                errors.items ??
                errors.amount_received ??
                errors.order_type ??
                errors.gcash_reference_number ??
                errors.gcash_proof ??
                'Transaction failed. Please try again.';
        },
    });
}

/* --- Transaction Completion / Receipt Confirmation modal --- */
const page = usePage<{ flash: { success?: string; transaction?: CompletedTransaction } }>();

const showTransactionComplete = ref(false);
const completedTransaction = ref<CompletedTransaction | null>(null);

// Only opens when a `transaction` flash is actually present (e.g. right
// after a successful checkout) — never on a plain page load/navigation.
watch(
    () => page.props.flash?.transaction,
    (transaction) => {
        if (transaction) {
            completedTransaction.value = transaction;
            showTransactionComplete.value = true;
        }
    },
    { immediate: true },
);

function resetOrderState() {
    clearCart();
    orderDetails.value = null;
    orderSummaryRef.value?.resetPayment();
}

function closeTransactionComplete() {
    showTransactionComplete.value = false;
    resetOrderState();
}

function handleNewTransaction() {
    showTransactionComplete.value = false;
    resetOrderState();
}

function handlePrintReceipt() {
    window.print();
}
</script>

<template>
    <Head title="Products" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Payment error toast -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="payError"
                class="fixed right-4 top-4 z-50 max-w-[calc(100vw-2rem)] rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive shadow-lg"
            >
                {{ payError }}
            </div>
        </Transition>

        <div class="grid h-full flex-1 grid-cols-1 gap-4 p-3 sm:p-4 lg:grid-cols-[1fr_340px] xl:grid-cols-[1fr_380px]">
            <!-- Left: Product listing -->
            <div class="flex min-w-0 flex-col gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="text-xl font-semibold text-foreground">Products</h1>
                </div>

                <div class="flex flex-col gap-3">
                    <div class="relative w-full sm:max-w-xs">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-foreground/60" />
                        <Input v-model="search" type="text" placeholder="Search products..." class="w-full pl-9" />
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                        <select
                            v-model="categoryFilter"
                            class="h-9 min-w-[140px] flex-1 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring sm:flex-none"
                        >
                            <option value="">All Categories</option>
                            <option v-for="cat in props.categories" :key="cat.id" :value="cat.name">{{ cat.name }}</option>
                        </select>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="text-sm text-foreground/60 underline-offset-2 hover:underline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </button>
                        <Loader2 v-if="isFiltering" class="h-4 w-4 animate-spin text-foreground/60" />
                    </div>
                </div>

                <div class="relative flex-1 overflow-auto rounded-2xl border border-sidebar-border/70 p-3 dark:border-sidebar-border sm:p-5">
                    <div v-if="props.products.length > 0" class="flex flex-col gap-6 sm:gap-8">
                        <section v-for="section in sections" :key="section.key">
                            <span
                                class="mb-3 inline-block rounded-full px-4 py-1.5 text-sm font-medium"
                                :class="
                                    section.key === '__out_of_stock'
                                        ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                                        : 'bg-muted text-foreground'
                                "
                            >
                                {{ section.label }}
                            </span>

                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-5">
                                <button
                                    v-for="product in section.products"
                                    :key="product.id"
                                    type="button"
                                    :disabled="!product.is_available"
                                    class="flex flex-col gap-2 rounded-2xl border border-[#2a5049] bg-[#173832] p-2 text-left shadow-lg shadow-black/20 transition-colors hover:border-[#3d6b62] disabled:cursor-not-allowed disabled:opacity-60 sm:gap-3 sm:p-3"
                                    @click="addToCart(product)"
                                >
                                    <div class="relative aspect-square overflow-hidden rounded-xl bg-[#0d2b25]">
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            :alt="product.name"
                                            class="h-full w-full object-cover"
                                        />
                                        <div v-else class="flex h-full w-full items-center justify-center">
                                            <ImageOff class="h-6 w-6 text-[#3d6b62] sm:h-8 sm:w-8" />
                                        </div>

                                        <div
                                            class="absolute left-1.5 top-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-[#0d2b25]/80 text-[10px] font-semibold text-[#d8a851] ring-1 ring-[#d8a851]/60 backdrop-blur sm:left-2 sm:top-2 sm:h-7 sm:w-7 sm:text-xs"
                                            :title="product.category"
                                        >
                                            {{ categoryInitial(product.category) }}
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-0.5">
                                        <h3 class="truncate text-xs font-semibold text-[#f5efe0] sm:text-sm" :title="product.name">
                                            {{ product.name }}
                                        </h3>
                                        <p class="text-xs font-bold text-[#d8a851] sm:text-sm">₱{{ Number(product.price).toFixed(2) }}</p>
                                        <p v-if="product.stock_left !== null" class="truncate text-[11px] text-[#9db8ae] sm:text-xs">
                                            {{ product.stock_left }} pcs left
                                        </p>
                                        <p class="mt-1 flex items-center gap-1.5 text-[11px] sm:text-xs">
                                            <span
                                                class="h-1.5 w-1.5 shrink-0 rounded-full"
                                                :class="product.is_available ? 'bg-emerald-400' : 'bg-[#9db8ae]'"
                                            />
                                            <span :class="product.is_available ? 'text-emerald-300' : 'text-[#9db8ae]'">
                                                {{ product.is_available ? 'Available' : 'Unavailable' }}
                                            </span>
                                        </p>
                                    </div>
                                </button>
                            </div>
                        </section>
                    </div>

                    <div v-else class="flex min-h-[250px] flex-col items-center justify-center gap-3 px-4 text-center text-foreground/60">
                        <ImageOff class="h-10 w-10" />
                        <p v-if="hasActiveFilters">No products match your filters.</p>
                        <p v-else>No products available yet.</p>
                    </div>
                </div>
            </div>

            <!-- Right: Order Summary -->
            <div class="lg:sticky lg:top-4 lg:h-[calc(100vh-2rem)]">
                <TypedOrderSummary
                    ref="orderSummaryRef"
                    :items="cart"
                    :processing="payForm.processing"
                    :order-number="props.next_order_number"
                    :order-type="orderDetails?.orderType ?? null"
                    @increment="incrementQty"
                    @decrement="decrementQty"
                    @remove="removeFromCart"
                    @clear="clearCart"
                    @pay="handlePay"
                    @edit-order="openOrderOptions"
                />
            </div>
        </div>

        <TypedOrderOptions ref="orderOptionsRef" :order-number="props.next_order_number" @confirm="handleOrderOptionsConfirm" />

        <TransactionComplete
            :open="showTransactionComplete"
            :transaction="completedTransaction"
            @close="closeTransactionComplete"
            @new-transaction="handleNewTransaction"
            @print-receipt="handlePrintReceipt"
        />

        <TransactionReceipt v-if="completedTransaction" :transaction="completedTransaction" />
    </AppLayout>
</template>
