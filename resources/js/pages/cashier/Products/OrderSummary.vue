<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { AlertCircle, Coffee, Minus, Pencil, Plus, ShoppingBag, Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

export interface CartItem {
    id: number;
    name: string;
    price: number;
    qty: number;
}

export type PaymentPayload =
    | { method: 'cash'; amountReceived: number; change: number; total: number }
    | { method: 'gcash'; referenceNumber: string; amountPaid: number; proofImage: File; total: number };

const props = withDefaults(
    defineProps<{
        items: CartItem[];
        processing?: boolean;
        orderNumber: number | null;
        orderType: 'dine_in' | 'take_out' | null;
    }>(),
    {
        items: () => [],
    },
);

const emit = defineEmits<{
    increment: [id: number];
    decrement: [id: number];
    remove: [id: number];
    clear: [];
    pay: [PaymentPayload];
    'edit-order': [];
}>();

const itemCount = computed(() => (props.items ?? []).reduce((sum, item) => sum + item.qty, 0));
const total = computed(() => (props.items ?? []).reduce((sum, item) => sum + item.price * item.qty, 0));
const hasOrderDetails = computed(() => props.orderType !== null);

function formatCurrency(value: number): string {
    return `₱${value.toFixed(2)}`;
}

function formatOrderNumber(n: number | null): string {
    return n !== null ? `#${String(n).padStart(3, '0')}` : '—';
}

/* --- Payment method --- */
const paymentMethod = ref<'cash' | 'gcash'>('cash');

/* --- Cash --- */
const amountReceived = ref<string>('');
const receivedNumber = computed(() => Number(amountReceived.value) || 0);
const change = computed(() => Math.max(0, receivedNumber.value - total.value));
const shortAmount = computed(() => Math.max(0, total.value - receivedNumber.value));
const hasEnteredAmount = computed(() => amountReceived.value !== '');

const canCompleteCashSale = computed(() => props.items.length > 0 && hasOrderDetails.value && receivedNumber.value >= total.value);

function handleCompleteSale() {
    if (!canCompleteCashSale.value) return;

    emit('pay', {
        method: 'cash',
        amountReceived: receivedNumber.value,
        change: change.value,
        total: total.value,
    });
}

/* --- GCash --- */
const referenceNumber = ref('');
const amountPaid = ref('');
const paidNumber = computed(() => Number(amountPaid.value) || 0);
const hasEnteredAmountPaid = computed(() => amountPaid.value !== '');
const gcashShortAmount = computed(() => Math.max(0, total.value - paidNumber.value));

const proofFile = ref<File | null>(null);
const proofPreview = ref<string | null>(null);

function setProofFile(file: File | null) {
    if (proofPreview.value) URL.revokeObjectURL(proofPreview.value);
    proofFile.value = file;
    proofPreview.value = file ? URL.createObjectURL(file) : null;
}

function onProofChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    setProofFile(file);
}

function removeProof() {
    setProofFile(null);
}

const canConfirmGcash = computed(
    () =>
        props.items.length > 0 &&
        hasOrderDetails.value &&
        referenceNumber.value.trim() !== '' &&
        paidNumber.value >= total.value &&
        proofFile.value !== null,
);

function handleConfirmGcash() {
    if (!canConfirmGcash.value || !proofFile.value) return;

    emit('pay', {
        method: 'gcash',
        referenceNumber: referenceNumber.value.trim(),
        amountPaid: paidNumber.value,
        proofImage: proofFile.value,
        total: total.value,
    });
}

/* --- Shared reset --- */
// Called by the parent once the sale actually succeeds (not eagerly on
// submit) so a failed/rejected payment doesn't wipe what the cashier typed
// or force re-uploading the GCash screenshot.
function resetPayment() {
    amountReceived.value = '';
    referenceNumber.value = '';
    amountPaid.value = '';
    setProofFile(null);
}

function handleClear() {
    emit('clear');
    resetPayment();
}

defineExpose({ resetPayment });
</script>

<template>
    <div class="flex h-full flex-col overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
        <!-- Header -->
        <div class="flex items-center justify-between bg-[#173832] px-4 py-3">
            <h2 class="text-sm font-semibold text-[#f5efe0]">Order Summary</h2>
            <span class="rounded-full bg-[#d8a851] px-2.5 py-0.5 text-xs font-semibold text-[#173832]">
                {{ itemCount }} {{ itemCount === 1 ? 'item' : 'items' }}
            </span>
        </div>

        <!-- Order details strip: order number + dine-in/take-out -->
        <button
            type="button"
            class="flex items-center justify-between gap-2 border-b border-sidebar-border/60 bg-[#0d2b25]/[0.04] px-4 py-2.5 text-left transition-colors hover:bg-[#0d2b25]/[0.08] dark:bg-[#0d2b25]/30 dark:hover:bg-[#0d2b25]/50"
            @click="emit('edit-order')"
        >
            <div class="flex items-center gap-3 text-sm">
                <span class="font-semibold text-[#173832] dark:text-[#f5efe0]">
                    {{ formatOrderNumber(props.orderNumber) }}
                </span>

                <span v-if="hasOrderDetails" class="flex items-center gap-1 text-muted-foreground">
                    <Coffee v-if="props.orderType === 'dine_in'" class="h-3.5 w-3.5" />
                    <ShoppingBag v-else class="h-3.5 w-3.5" />
                    {{ props.orderType === 'dine_in' ? 'Dine-in' : 'Take-out' }}
                </span>
                <span v-else class="text-[#d8a851]"> Set order details </span>
            </div>

            <Pencil class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        </button>

        <!-- Items -->
        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <div
                v-if="items.length === 0"
                class="flex h-full min-h-[160px] flex-col items-center justify-center gap-2 text-center text-muted-foreground"
            >
                <ShoppingBag class="h-8 w-8" />
                <p class="text-sm">No items yet.</p>
                <p class="text-xs">Tap a product to add it here.</p>
            </div>

            <div v-else class="flex flex-col gap-2.5">
                <div v-for="item in items" :key="item.id" class="rounded-xl border-2 border-sidebar-border/70 bg-background p-3 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 flex-1 break-words text-base font-bold leading-snug text-foreground">
                            {{ item.name }}
                        </p>
                        <button
                            type="button"
                            class="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                            :aria-label="`Remove ${item.name}`"
                            @click="emit('remove', item.id)"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="mt-1.5 flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-foreground/80">{{ formatCurrency(item.price) }}</p>
                        <p class="shrink-0 whitespace-nowrap text-base font-extrabold text-foreground">
                            {{ formatCurrency(item.price * item.qty) }}
                        </p>
                    </div>

                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-sidebar-border/50 pt-2">
                        <span class="text-xs font-medium text-muted-foreground">Qty</span>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border-2 border-input bg-muted/50 font-bold text-foreground hover:bg-muted"
                                :aria-label="`Decrease ${item.name} quantity`"
                                @click="emit('decrement', item.id)"
                            >
                                <Minus class="h-4 w-4" />
                            </button>
                            <span class="min-w-8 rounded-md bg-[#173832] px-2 py-1 text-center text-base font-bold tabular-nums text-[#f5efe0]">{{
                                item.qty
                            }}</span>
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border-2 border-input bg-muted/50 font-bold text-foreground hover:bg-muted"
                                :aria-label="`Increase ${item.name} quantity`"
                                @click="emit('increment', item.id)"
                            >
                                <Plus class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment / Checkout -->
        <div class="flex flex-col gap-4 border-t border-sidebar-border/70 bg-[#0d2b25]/[0.03] px-4 py-4 dark:bg-[#0d2b25]/40">
            <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Total Amount</span>
                <span class="text-lg font-bold text-[#173832] dark:text-[#f5efe0]">{{ formatCurrency(total) }}</span>
            </div>

            <!-- Payment method switch -->
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-medium transition-colors"
                    :class="
                        paymentMethod === 'cash'
                            ? 'border-[#d8a851] bg-[#d8a851]/10 text-[#173832] dark:text-[#f5efe0]'
                            : 'border-input text-muted-foreground hover:bg-muted'
                    "
                    @click="paymentMethod = 'cash'"
                >
                    <span></span>
                    Cash
                </button>
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-medium transition-colors"
                    :class="
                        paymentMethod === 'gcash'
                            ? 'border-[#d8a851] bg-[#d8a851]/10 text-[#173832] dark:text-[#f5efe0]'
                            : 'border-input text-muted-foreground hover:bg-muted'
                    "
                    @click="paymentMethod = 'gcash'"
                >
                    <span></span>
                    GCash
                </button>
            </div>

            <!-- CASH fields -->
            <template v-if="paymentMethod === 'cash'">
                <div class="flex flex-col gap-2">
                    <label class="text-xs font-medium text-muted-foreground">Amount Received</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-[#d8a851]"> ₱ </span>
                        <input
                            v-model="amountReceived"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            class="no-spinner h-11 w-full rounded-lg border-2 border-[#2a5049]/40 bg-background pl-7 pr-3 text-right text-lg font-semibold text-[#173832] shadow-sm transition-colors focus:border-[#d8a851] focus:outline-none focus:ring-2 focus:ring-[#d8a851]/30 dark:text-[#f5efe0]"
                        />
                    </div>
                </div>

                <!-- Automatically calculated change -->
                <div
                    class="flex items-center justify-between rounded-lg px-3 py-2"
                    :class="shortAmount > 0 && hasEnteredAmount ? 'bg-destructive/10' : 'bg-emerald-500/10'"
                >
                    <span
                        class="flex items-center gap-1.5 text-sm font-medium"
                        :class="shortAmount > 0 && hasEnteredAmount ? 'text-destructive' : 'text-emerald-600 dark:text-emerald-400'"
                    >
                        <AlertCircle v-if="shortAmount > 0 && hasEnteredAmount" class="h-3.5 w-3.5" />
                        {{ shortAmount > 0 && hasEnteredAmount ? 'Short' : 'Change' }}
                    </span>
                    <span
                        class="text-base font-bold"
                        :class="shortAmount > 0 && hasEnteredAmount ? 'text-destructive' : 'text-emerald-600 dark:text-emerald-400'"
                    >
                        {{ formatCurrency(shortAmount > 0 && hasEnteredAmount ? shortAmount : change) }}
                    </span>
                </div>

                <p v-if="!hasOrderDetails && items.length > 0" class="text-center text-xs text-[#d8a851]">
                    Set order details above before taking payment.
                </p>

                <div class="flex flex-col gap-2">
                    <Button
                        class="h-11 w-full bg-[#d8a851] text-base font-semibold text-[#173832] hover:bg-[#c99a44]"
                        :disabled="!canCompleteCashSale || processing"
                        @click="handleCompleteSale"
                    >
                        Complete Sale — {{ formatCurrency(total) }}
                    </Button>
                    <Button variant="ghost" class="w-full" :disabled="items.length === 0" @click="handleClear"> Clear </Button>
                </div>
            </template>

            <!-- GCASH fields -->
            <template v-else>
                <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-foreground">GCash Reference Number</label>
                    <input
                        v-model="referenceNumber"
                        type="text"
                        maxlength="50"
                        placeholder="e.g. 1234567890123"
                        class="h-11 w-full rounded-lg border-2 border-[#2a5049]/40 bg-background px-3 text-base font-semibold text-[#173832] shadow-sm transition-colors placeholder:font-normal placeholder:text-muted-foreground/60 focus:border-[#d8a851] focus:outline-none focus:ring-2 focus:ring-[#d8a851]/30 dark:text-[#f5efe0]"
                    />
                </div>

                <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-foreground">Amount Paid</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-[#d8a851]"> ₱ </span>
                        <input
                            v-model="amountPaid"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            class="no-spinner h-11 w-full rounded-lg border-2 border-[#2a5049]/40 bg-background pl-7 pr-3 text-right text-lg font-semibold text-[#173832] shadow-sm transition-colors placeholder:font-normal placeholder:text-muted-foreground/60 focus:border-[#d8a851] focus:outline-none focus:ring-2 focus:ring-[#d8a851]/30 dark:text-[#f5efe0]"
                        />
                    </div>
                    <p v-if="gcashShortAmount > 0 && hasEnteredAmountPaid" class="flex items-center gap-1 text-xs text-destructive">
                        <AlertCircle class="h-3 w-3" />
                        Amount paid is {{ formatCurrency(gcashShortAmount) }} short of the total.
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="text-xs font-medium text-muted-foreground">GCash Payment Proof</label>

                    <label
                        v-if="!proofPreview"
                        class="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-[#2a5049]/40 px-3 py-6 text-center text-xs text-muted-foreground transition-colors hover:border-[#d8a851] hover:text-[#d8a851]"
                    >
                        <Upload class="h-5 w-5" />
                        Tap to upload screenshot
                        <input type="file" accept="image/*" class="hidden" @change="onProofChange" />
                    </label>

                    <div v-else class="relative overflow-hidden rounded-lg border-2 border-[#2a5049]/40">
                        <img :src="proofPreview" alt="GCash payment proof" class="h-40 w-full object-cover" />
                        <div class="absolute inset-x-0 bottom-0 flex justify-end gap-1.5 bg-black/50 p-1.5">
                            <label class="cursor-pointer rounded-md bg-white/90 px-2 py-1 text-[11px] font-medium text-[#173832] hover:bg-white">
                                Replace
                                <input type="file" accept="image/*" class="hidden" @change="onProofChange" />
                            </label>
                            <button
                                type="button"
                                class="rounded-md bg-white/90 px-2 py-1 text-[11px] font-medium text-destructive hover:bg-white"
                                @click="removeProof"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                </div>

                <p v-if="!hasOrderDetails && items.length > 0" class="text-center text-xs text-[#d8a851]">
                    Set order details above before taking payment.
                </p>

                <div class="flex flex-col gap-2">
                    <Button
                        class="h-11 w-full bg-[#d8a851] text-base font-semibold text-[#173832] hover:bg-[#c99a44]"
                        :disabled="!canConfirmGcash || processing"
                        @click="handleConfirmGcash"
                    >
                        Confirm GCash Payment — {{ formatCurrency(total) }}
                    </Button>
                    <Button variant="ghost" class="w-full" :disabled="items.length === 0" @click="handleClear"> Clear </Button>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Hide native number input spin buttons (Chrome, Safari, Edge) */
.no-spinner::-webkit-outer-spin-button,
.no-spinner::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* Hide native number input spin buttons (Firefox) */
.no-spinner {
    -moz-appearance: textfield;
    appearance: textfield;
}
</style>
