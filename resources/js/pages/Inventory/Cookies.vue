<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ImageOff, Loader2, Minus, Package, PackageX, Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { usePermissions } from '@/composables/usePermissions';

interface CookieProduct {
    id: number;
    name: string;
    category: string;
    price: string;
    image_url: string | null;
    is_available: boolean;
    stock_quantity: number | null;
}

interface CategoryOption {
    id: number;
    name: string;
}

const props = defineProps<{
    products: CookieProduct[];
    categories: CategoryOption[];
    filters: { search?: string; category?: string };
}>();
const { canManageInventory } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pastries & Baked Goods', href: '/inventory/cookies' }];

const page = usePage<{ flash?: { success?: string; error?: string } }>();

/* ---------------------------------------------------------------------- */
/* Notifications                                                          */
/* ---------------------------------------------------------------------- */

const notification = ref<{ type: 'success' | 'error'; message: string } | null>(null);
let notificationTimeout: ReturnType<typeof setTimeout> | null = null;

function showNotification(type: 'success' | 'error', message: string) {
    notification.value = { type, message };
    if (notificationTimeout) clearTimeout(notificationTimeout);
    notificationTimeout = setTimeout(() => (notification.value = null), 3500);
}

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    },
    { deep: true },
);

/* ---------------------------------------------------------------------- */
/* Search + category filter                                               */
/* ---------------------------------------------------------------------- */

const search = ref(props.filters.search ?? '');
const categoryFilter = ref(props.filters.category ?? '');
const isFiltering = ref(false);
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

function applyFilters() {
    isFiltering.value = true;
    router.get(
        route('inventory.cookies'),
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

/* ---------------------------------------------------------------------- */
/* Summary stats                                                          */
/* ---------------------------------------------------------------------- */

const stats = computed(() => ({
    total: props.products.length,
    outOfStock: props.products.filter((p) => (p.stock_quantity ?? 0) <= 0).length,
}));

/* ---------------------------------------------------------------------- */
/* Adjust stock                                                           */
/* ---------------------------------------------------------------------- */

const adjustTarget = ref<CookieProduct | null>(null);
const adjustForm = useForm({ delta: '' as number | '' });

function openAdjust(product: CookieProduct) {
    adjustTarget.value = product;
    adjustForm.reset();
    adjustForm.clearErrors();
}

function closeAdjust() {
    adjustTarget.value = null;
    adjustForm.reset();
    adjustForm.clearErrors();
}

function submitAdjust(sign: 1 | -1) {
    if (!adjustTarget.value || adjustForm.delta === '') return;

    const delta = Math.abs(Number(adjustForm.delta)) * sign;

    adjustForm.transform(() => ({ delta })).post(route('inventory.cookies.adjust-stock', adjustTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => closeAdjust(),
    });
}

const projectedStock = computed(() => {
    if (!adjustTarget.value || adjustForm.delta === '') return null;
    return (adjustTarget.value.stock_quantity ?? 0) + Number(adjustForm.delta);
});
</script>

<template>
    <Head title="Pastries & Baked Goods" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Toast notification -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="notification"
                class="fixed right-4 top-4 z-50 rounded-lg border px-4 py-3 text-sm shadow-lg"
                :class="
                    notification.type === 'success'
                        ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-300'
                        : 'border-destructive/30 bg-destructive/10 text-destructive'
                "
            >
                {{ notification.message }}
            </div>
        </Transition>

        <div class="flex h-full flex-1 flex-col gap-5 rounded-xl p-4">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-foreground">Pastries & Baked Goods</h1>
                    <p class="text-sm text-foreground/60">
                        On-hand stock counts for finished products. To add a new item, use the Products page.
                    </p>
                </div>
            </div>

            <!-- Summary stat cards -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                        <Package class="h-5 w-5 text-foreground/60" />
                    </div>
                    <div>
                        <p class="text-lg font-semibold leading-none text-foreground">{{ stats.total }}</p>
                        <p class="text-xs text-foreground/60">Items tracked</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-destructive/10">
                        <PackageX class="h-5 w-5 text-destructive" />
                    </div>
                    <div>
                        <p class="text-lg font-semibold leading-none text-foreground">{{ stats.outOfStock }}</p>
                        <p class="text-xs text-foreground/60">Out of stock</p>
                    </div>
                </div>
            </div>

            <!-- Search + category filter -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-full max-w-xs">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-foreground/60" />
                    <Input v-model="search" type="text" placeholder="Search items..." class="pl-9" />
                </div>

                <!-- <select
                    v-model="categoryFilter"
                    class="h-9 min-w-[140px] rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                >
                    <option value="">All Categories</option>
                    <option v-for="cat in props.categories" :key="cat.id" :value="cat.name">{{ cat.name }}</option>
                </select> -->

                <Button v-if="hasActiveFilters" variant="ghost" size="sm" @click="clearFilters">Clear filters</Button>
                <Loader2 v-if="isFiltering" class="h-4 w-4 animate-spin text-foreground/60" />
            </div>

            <!-- Product cards -->
            <div v-if="props.products.length > 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="product in props.products"
                    :key="product.id"
                    class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:border-sidebar-border dark:border-sidebar-border"
                >
                    <div class="flex items-start gap-2.5">
                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-muted">
                            <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
                            <div v-else class="flex h-full w-full items-center justify-center">
                                <ImageOff class="h-4 w-4 text-foreground/60" />
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold leading-tight text-foreground">{{ product.name }}</h3>
                            <p class="text-xs text-foreground/60">{{ product.category }}</p>
                        </div>
                    </div>

                    <div class="flex items-end justify-between">
                        <div>
                            <p class="text-2xl font-bold leading-none text-foreground">
                                {{ product.stock_quantity ?? 0 }}
                                <span class="text-sm font-medium text-foreground/60">pcs</span>
                            </p>
                            <p class="mt-1 text-xs text-foreground/60">₱{{ Number(product.price).toFixed(2) }}</p>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="
                                (product.stock_quantity ?? 0) <= 0
                                    ? 'bg-destructive/10 text-destructive'
                                    : 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400'
                            "
                        >
                            {{ (product.stock_quantity ?? 0) <= 0 ? 'Out of Stock' : 'In Stock' }}
                        </span>
                    </div>

                    <div class="mt-1 flex justify-end border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                        <Button v-if="canManageInventory" variant="outline" size="sm" @click="openAdjust(product)" class="text-foreground/60 hover:text-foreground">
                            <Plus class="mr-1.5 h-3.5 w-3.5" />
                            Adjust Stock
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-else
                class="flex min-h-[250px] flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 text-center text-foreground/60 dark:border-sidebar-border"
            >
                <p v-if="hasActiveFilters">No items match your filters.</p>
                <p v-else>
                    No finished-stock items yet. Create a product with tracking type "Finished Stock" on the Products page to see it here.
                </p>
            </div>
        </div>

        <!-- Adjust Stock -->
        <Dialog :open="!!adjustTarget" @update:open="(val) => !val && closeAdjust()">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Adjust Stock — {{ adjustTarget?.name }}</DialogTitle>
                </DialogHeader>

                <div class="space-y-4">
                    <p class="text-sm text-foreground/60">
                        Current stock: <span class="font-medium text-foreground">{{ adjustTarget?.stock_quantity ?? 0 }} pcs</span>
                    </p>

                    <div class="space-y-2">
                        <Label for="cookie-delta">Quantity</Label>
                        <Input id="cookie-delta" v-model="adjustForm.delta" type="number" min="1" step="1" placeholder="e.g. 24" />
                        <p v-if="adjustForm.errors.delta" class="text-sm text-destructive">{{ adjustForm.errors.delta }}</p>
                        <p v-if="projectedStock !== null" class="text-xs text-foreground/60">
                            New total after adding: {{ projectedStock }} pcs · after removing: {{ (adjustTarget?.stock_quantity ?? 0) - Number(adjustForm.delta) }} pcs
                        </p>
                    </div>
                </div>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button type="button" variant="outline" @click="closeAdjust">Cancel</Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="adjustForm.processing || adjustForm.delta === ''"
                        @click="submitAdjust(-1)"
                        class="text-foreground/60 hover:text-foreground"
                    >
                        <Loader2 v-if="adjustForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                        <Minus v-else class="mr-2 h-4 w-4" />
                        Remove
                    </Button>
                    <Button type="button" :disabled="adjustForm.processing || adjustForm.delta === ''" @click="submitAdjust(1)">
                        <Loader2 v-if="adjustForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                        <Plus v-else class="mr-2 h-4 w-4" />
                        Add
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>