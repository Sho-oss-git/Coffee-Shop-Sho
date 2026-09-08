<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\ActionRequest;
use App\Services\IngredientConsumptionService;
use App\Http\Requests\RefundTransactionRequest;
use App\Http\Requests\VoidTransactionRequest;
use App\Services\ProductCostService;
use App\Exports\SalesReportExport;
use App\Exports\SalesReportWordExport;
use App\Exports\Sheets\TransactionLogSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TransactionController extends Controller
{
    public function __construct(
        private readonly IngredientConsumptionService $consumption,
        private readonly ProductCostService $costs,
    ) {}

    public function dashboard(Request $request)
    {
        $today = now()->toDateString();

        $period = $request->input('period', 'daily');
        if (! in_array($period, ['daily', 'monthly', 'yearly'], true)) {
            $period = 'daily';
        }

        $anchor = $this->resolvePeriodAnchor($period, $request->input('date', $today));

        // Normalize into the same format salesPeriodQuery() already expects
        // for each period (daily: Y-m-d, monthly: Y-m, yearly: Y).
        $normalizedDate = match ($period) {
            'monthly' => $anchor->format('Y-m'),
            'yearly' => $anchor->format('Y'),
            default => $anchor->format('Y-m-d'),
        };

        $transactions = $this->salesPeriodQuery($period, $normalizedDate)->get();
        $items = $transactions->flatMap->items;

        // "Recent transactions" stays a live/global feed (not period-scoped) —
        // it's meant to show what's happening right now, regardless of which
        // period the trend chart is looking at.
        $recentTransactions = Transaction::with('user:id,name')
            ->where('status', 'completed')
            ->latest()
            ->take(6)
            ->get();

        return Inertia::render('Dashboard', [
            'summary' => [
                'sales' => round((float) $transactions->sum('total'), 2),
                'transactions' => $transactions->count(),
                'items' => (int) $items->sum('quantity'),
                'average_sale' => $transactions->count()
                    ? round((float) $transactions->sum('total') / $transactions->count(), 2)
                    : 0,
            ],
            'recentTransactions' => $recentTransactions->map(fn ($transaction) => [
                'id' => $transaction->id,
                'time' => $transaction->created_at->format('g:i A'),
                'cashier' => $transaction->user?->name ?? 'Unknown',
                'total' => (float) $transaction->total,
                'payment_method' => $transaction->payment_method,
            ])->values(),
            'pendingRequests' => ActionRequest::where('status', 'pending')->count(),
            'productCount' => Product::where('is_available', true)->count(),
            'date' => $today,
            'salesTrend' => $this->buildDashboardTrend($period, $anchor, $transactions),
            'filters' => [
                'period' => $period,
                'date' => $normalizedDate,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'amount_received' => ['required', 'numeric', 'min:0'],
            'order_type' => ['required', Rule::in(['dine_in', 'take_out'])],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', Rule::in(['cash', 'gcash'])],
            // Only required when paying via GCash — proof of the transfer
            // and its reference number are how a cashier/manager later
            // reconciles this sale against the actual GCash account.
            'gcash_reference_number' => ['required_if:payment_method,gcash', 'nullable', 'string', 'max:50'],
            'gcash_proof' => ['required_if:payment_method,gcash', 'nullable', 'image', 'max:4096'],
        ]);

        $products = Product::with('recipe.ingredient')
            ->whereIn('id', collect($validated['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        // ---- STEP 1: validate every line BEFORE touching any stock ----
        $ingredientRequirements = [];
        $finishedStockLines = []; // ['product' => Product, 'quantity' => int]
        $shortages = [];
        $total = 0;

        foreach ($validated['items'] as $line) {
            $product = $products->get($line['product_id']);

            if (! $product || ! $product->is_available) {
                throw ValidationException::withMessages([
                    'items' => ($product->name ?? 'A product') . ' is no longer available.',
                ]);
            }

            $total += (float) $product->price * $line['quantity'];

            if ($product->tracking_type === 'finished_stock') {
                $finishedStockLines[] = ['product' => $product, 'quantity' => $line['quantity']];

                if ((int) $product->stock_quantity < $line['quantity']) {
                    $shortages[] = [
                        'ingredient' => $product->name,
                        'required' => $line['quantity'],
                        'available' => $product->stock_quantity,
                        'unit' => 'pcs',
                    ];
                }
            } else {
                $requirements = $this->consumption->calculateRequirements($product, $line['quantity']);
                $ingredientRequirements = $this->consumption->mergeRequirements($ingredientRequirements, $requirements);
            }
        }

        $shortages = array_merge($shortages, $this->consumption->checkAvailability($ingredientRequirements));

        if (! empty($shortages)) {
            throw ValidationException::withMessages([
                'items' => 'Insufficient stock: ' . collect($shortages)
                    ->map(fn ($s) => "{$s['ingredient']} (need {$s['required']}{$s['unit']}, have {$s['available']}{$s['unit']})")
                    ->implode('; '),
            ]);
        }

        if ((float) $validated['amount_received'] < $total) {
            throw ValidationException::withMessages([
                'amount_received' => $validated['payment_method'] === 'gcash'
                    ? 'Amount paid is less than the total.'
                    : 'Amount received is less than the total.',
            ]);
        }

        // File storage isn't transactional, so it happens outside
        // DB::transaction below — same pattern as Product image uploads.
        $gcashProofPath = null;
        if ($request->hasFile('gcash_proof')) {
            $gcashProofPath = $request->file('gcash_proof')->store('gcash-proofs', 'public');
        }

        // ---- STEP 2: everything checks out — commit atomically ----
        $transaction = DB::transaction(function () use ($validated, $products, $finishedStockLines, $ingredientRequirements, $total, $request, $gcashProofPath) {
            // Daily sequential order number (resets each day) for the
            // cashier/receipt to display. Locks the latest transaction row
            // for today (if one exists) so a concurrent checkout blocks
            // until this one commits, avoiding duplicate numbers.
            //
            // Known limitation: the very first order of a new day has no
            // row yet to lock, so two cashiers checking out in the same
            // instant as the day's first order could theoretically both
            // land on order_number 1. Acceptable for this scale; if that
            // ever becomes a real problem, switch to a dedicated daily
            // counters table with an atomic increment.
            $lastToday = Transaction::whereDate('created_at', now()->toDateString())
                ->lockForUpdate()
                ->orderByDesc('order_number')
                ->first();

            $orderNumber = $lastToday ? $lastToday->order_number + 1 : 1;

            $transaction = Transaction::create([
                'user_id' => $request->user()->id,
                'order_number' => $orderNumber,
                'order_type' => $validated['order_type'],
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'payment_method' => $validated['payment_method'],
                'gcash_reference_number' => $validated['gcash_reference_number'] ?? null,
                'gcash_proof' => $gcashProofPath,
                'total' => $total,
                'amount_received' => $validated['amount_received'],
                'change' => $validated['amount_received'] - $total,
                'status' => 'completed',
            ]);

            foreach ($validated['items'] as $line) {
                $product = $products->get($line['product_id']);

                // COGS is snapshotted from the product's cost AT THIS MOMENT.
                // Never recomputed later from current recipe/ingredient
                // prices — historical reports must stay stable even after
                // recipes or ingredient costs change.
                $unitCost = $this->costs->currentCost($product);
                $subtotal = $product->price * $line['quantity'];

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $product->price,
                    'quantity' => $line['quantity'],
                    'subtotal' => $subtotal,
                    'unit_cost' => $unitCost,
                    'cogs' => $unitCost !== null ? round($unitCost * $line['quantity'], 2) : null,
                ]);
            }

            foreach ($finishedStockLines as $line) {
                $line['product']->decrement('stock_quantity', $line['quantity']);
            }

            $this->consumption->consumeIngredients($ingredientRequirements);

            return $transaction;
        });

        // Reload the cashier/user relationship so the receipt modal can
        // show who processed the sale without an extra query from the
        // frontend. Falls back to the currently authenticated user in the
        // (practically impossible) case the relationship can't resolve.
        $transaction->loadMissing(['user', 'items']);

        return back()->with('success', 'Transaction completed.')->with('transaction', [
            'id' => $transaction->id,
            'order_number' => $transaction->order_number,
            'transaction_no' => '#TXN-' . str_pad((string) $transaction->order_number, 6, '0', STR_PAD_LEFT),
            'order_type' => $transaction->order_type,
            'customer_name' => $transaction->customer_name,
            'notes' => $transaction->notes,
            'payment_method' => $transaction->payment_method,
            'gcash_reference_number' => $transaction->gcash_reference_number,
            'gcash_proof_url' => $transaction->gcash_proof_url,
            'total' => (float) $transaction->total,
            'amount_received' => (float) $transaction->amount_received,
            'change' => (float) $transaction->change,
            'cashier' => $transaction->user->name ?? $request->user()->name,
            'created_at' => $transaction->created_at,
            'user' => ['name' => $transaction->user->name ?? $request->user()->name],
            'items' => $transaction->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'subtotal' => (float) $item->subtotal,
            ]),
        ]);
    }

    /**
     * Sales Monitoring — "what's happening with sales today" dashboard for
     * Admin/Manager. Always scoped to the current day; no period picker,
     * this is distinct from the historical Sales Report.
     */
    public function saleTransaction(Request $request)
    {
        $year = now()->year;

        $transactions = Transaction::with(['items', 'user:id,name'])
            ->where('status', 'completed')
            ->whereYear('created_at', $year)
            ->orderBy('created_at')
            ->get();

        $allItems = $transactions->flatMap->items;
        $totalCogs = (float) $allItems->whereNotNull('cogs')->sum('cogs');
        $totalRevenue = (float) $transactions->sum('total');
        $grossProfit = round($totalRevenue - $totalCogs, 2);

        $summary = [
            'total_sales' => round($totalRevenue, 2),
            'transaction_count' => $transactions->count(),
            'items_sold' => (int) $allItems->sum('quantity'),
            'average_sale' => $transactions->count()
                ? round($totalRevenue / $transactions->count(), 2)
                : 0,
            'total_cogs' => round($totalCogs, 2),
            'gross_profit' => $grossProfit,
        ];

        // ---- Sales Trend Today: bucket by hour across business hours ----
        // Fixed 6 AM–9 PM window so the chart has a stable x-axis even on
        // slow days, rather than only showing hours that had activity.
        $salesByHour = $transactions
            ->groupBy(fn ($t) => (int) $t->created_at->format('G'))
            ->map(fn ($group) => round((float) $group->sum('total'), 2));

        $salesTrend = collect(range(6, 21))->map(fn ($hour) => [
            'hour' => $hour,
            'label' => \Carbon\Carbon::createFromTime($hour)->format('g A'),
            'sales' => $salesByHour->get($hour, 0),
        ])->values();

        // ---- Live / Recent Transactions: latest 8 today ----
        $recentTransactions = $transactions
            ->sortByDesc('created_at')
            ->take(8)
            ->map(fn ($t) => [
                'id' => $t->id,
                'time' => $t->created_at->format('g:i A'),
                'cashier' => $t->user?->name,
                'total' => round((float) $t->total, 2),
                'status' => $t->status,
                'payment_method' => $t->payment_method,
            ])
            ->values();

        // ---- Sales by Payment Method: cash vs GCash split for today ----
        $salesByPaymentMethod = $transactions
            ->groupBy(fn ($t) => $t->payment_method ?? 'cash')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'label' => $method === 'gcash' ? 'GCash' : 'Cash',
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
            ])
            ->values()
            ->sortByDesc('total')
            ->values();

        // ---- Current Best Sellers: today's top 5 products by qty ----
        $topProducts = $allItems
            ->groupBy('product_name')
            ->map(fn ($items, $name) => [
                'name' => $name,
                'quantity' => (int) $items->sum('quantity'),
            ])
            ->values()
            ->sortByDesc('quantity')
            ->take(5)
            ->values();

        // ---- Sales by Cashier: today's performance per cashier ----
        $salesByCashier = $transactions
            ->groupBy(fn ($t) => $t->user?->name ?? 'Unknown')
            ->map(fn ($group, $name) => [
                'cashier' => $name,
                'transaction_count' => $group->count(),
                'sales' => round((float) $group->sum('total'), 2),
            ])
            ->values()
            ->sortByDesc('sales')
            ->values();

        return Inertia::render('Sales/Transaction', [
            'summary' => $summary,
            'salesTrend' => $salesTrend,
            'recentTransactions' => $recentTransactions,
            'topProducts' => $topProducts,
            'salesByCashier' => $salesByCashier,
            'salesByPaymentMethod' => $salesByPaymentMethod,
            'date' => (string) $year,
        ]);
    }

    public function refund(RefundTransactionRequest $request, Transaction $transaction)
    {
        if ($transaction->status !== 'completed') {
            return back()->withErrors([
                'refund' => 'Only completed transactions can be refunded.',
            ]);
        }

        $validated = $request->validated();

        $transaction->update([
            'status' => 'refunded',
            'refund_amount' => $validated['refund_amount'],
            'refund_reason' => $validated['refund_reason'],
            'refunded_by' => $request->user()->id,
            'refunded_at' => now(),
        ]);

        return back()->with('success', 'Transaction refunded successfully.');
    }

    /**
     * Void a transaction (cancel it).
     */
    public function void(VoidTransactionRequest $request, Transaction $transaction)
    {
        if ($transaction->status !== 'completed') {
            return back()->withErrors([
                'void' => 'Only completed transactions can be voided.',
            ]);
        }

        $validated = $request->validated();

        $transaction->update([
            'status' => 'voided',
            'void_reason' => $validated['void_reason'],
            'voided_by' => $request->user()->id,
            'voided_at' => now(),
        ]);

        return back()->with('success', 'Transaction voided successfully.');
    }

    public function salesReport(Request $request)
    {
        $period = $request->input('period', 'daily');
        $date = $request->input('date', now()->toDateString());

        $transactions = $this->salesPeriodQuery($period, $date)->orderBy('created_at')->get();
        $allItems = $transactions->flatMap->items;

        $summary = $this->summarizeSales($transactions, $allItems);
        $productBreakdown = $this->productBreakdown($allItems);
        $salesByPaymentMethod = $this->paymentMethodSummary($transactions, (float) $summary['total_sales']);
        $orderTypeSummary = $this->orderTypeSummary($transactions);

        return Inertia::render('Reports/Sales', [
            'summary' => $summary,
            'bestSellers' => $productBreakdown->take(10)->values(),
            'salesByPaymentMethod' => $salesByPaymentMethod,
            'orderTypeSummary' => $orderTypeSummary,
            'filters' => [
                'period' => $period,
                'date' => $date,
            ],
        ]);
    }

    /**
     * Base query for the Sales Report, Print, Excel, and Word exports.
     *
     * By default only completed transactions are included (the single
     * source of truth for KPIs/COGS/best sellers). Pass $allStatuses to
     * also include refunded/voided transactions — used for the
     * Transaction Log, which must show them per spec, even though they
     * don't count toward Total Sales.
     */
    private function salesPeriodQuery(string $period, string $date, bool $allStatuses = false)
    {
        $query = Transaction::with(['items.product:id,category', 'user']);

        if ($allStatuses) {
            $query->whereIn('status', ['completed', 'refunded', 'voided']);
        } else {
            $query->where('status', 'completed');
        }

        switch ($period) {
            case 'monthly':
                $anchor = \Carbon\Carbon::parse($date.'-01'); // month input is "YYYY-MM"
                $query->whereYear('created_at', $anchor->year)
                      ->whereMonth('created_at', $anchor->month);
                break;

            case 'yearly':
                $query->whereYear('created_at', (int) $date);
                break;

            default: // daily
                $query->whereDate('created_at', $date);
                break;
        }

        return $query;
    }

    private function salesPeriodLabel(string $period, string $date): string
    {
        return match ($period) {
            'monthly' => \Carbon\Carbon::parse($date.'-01')->format('F Y'),
            'yearly' => (string) $date,
            default => \Carbon\Carbon::parse($date)->format('F j, Y'),
        };
    }

    /**
     * Turns whatever "date" the client sent (a full date, "YYYY-MM", a bare
     * year, or nothing at all) into a concrete Carbon instance representing
     * that period, regardless of exact format. Falls back to "now" if the
     * input can't be parsed at all.
     */
    private function resolvePeriodAnchor(string $period, string $date): \Carbon\Carbon
    {
        try {
            return match ($period) {
                'monthly' => preg_match('/^\d{4}-\d{2}$/', $date)
                    ? \Carbon\Carbon::createFromFormat('Y-m-d', $date.'-01')
                    : \Carbon\Carbon::parse($date)->startOfMonth(),
                'yearly' => preg_match('/^\d{4}$/', $date)
                    ? \Carbon\Carbon::createFromDate((int) $date, 1, 1)
                    : \Carbon\Carbon::parse($date)->startOfYear(),
                default => \Carbon\Carbon::parse($date),
            };
        } catch (\Exception) {
            return now();
        }
    }

    /**
     * Dashboard sales trend — hourly for daily, day-of-month for monthly,
     * month-of-year for yearly. Mirrors the "Sales Trend Today" logic from
     * saleTransaction(), generalized across periods. The frontend chart is
     * period-agnostic, so any of these shapes render fine as-is.
     */
    private function buildDashboardTrend(string $period, \Carbon\Carbon $anchor, $transactions): \Illuminate\Support\Collection
    {
        return match ($period) {
            'monthly' => $this->trendByDayOfMonth($anchor, $transactions),
            'yearly' => $this->trendByMonthOfYear($anchor, $transactions),
            default => $this->trendByHour($transactions),
        };
    }

    private function trendByHour($transactions): \Illuminate\Support\Collection
    {
        $salesByHour = $transactions
            ->groupBy(fn ($t) => (int) $t->created_at->format('G'))
            ->map(fn ($group) => round((float) $group->sum('total'), 2));

        return collect(range(6, 21))->map(fn ($hour) => [
            'hour' => $hour,
            'label' => \Carbon\Carbon::createFromTime($hour)->format('g A'),
            'sales' => $salesByHour->get($hour, 0),
        ])->values();
    }

    private function trendByDayOfMonth(\Carbon\Carbon $anchor, $transactions): \Illuminate\Support\Collection
    {
        $salesByDay = $transactions
            ->groupBy(fn ($t) => (int) $t->created_at->format('j'))
            ->map(fn ($group) => round((float) $group->sum('total'), 2));

        return collect(range(1, $anchor->daysInMonth))->map(fn ($day) => [
            'hour' => $day, // reused only as a unique point key by the frontend
            'label' => (string) $day,
            'sales' => $salesByDay->get($day, 0),
        ])->values();
    }

    private function trendByMonthOfYear(\Carbon\Carbon $anchor, $transactions): \Illuminate\Support\Collection
    {
        $salesByMonth = $transactions
            ->groupBy(fn ($t) => (int) $t->created_at->format('n'))
            ->map(fn ($group) => round((float) $group->sum('total'), 2));

        return collect(range(1, 12))->map(fn ($month) => [
            'hour' => $month,
            'label' => \Carbon\Carbon::createFromDate($anchor->year, $month, 1)->format('M'),
            'sales' => $salesByMonth->get($month, 0),
        ])->values();
    }

    private function summarizeSales($transactions, $allItems): array
    {
        $totalCogs = (float) $allItems->whereNotNull('cogs')->sum('cogs');
        $totalRevenue = (float) $transactions->sum('total');
        $grossProfit = round($totalRevenue - $totalCogs, 2);

        return [
            'total_sales' => round($totalRevenue, 2),
            'transaction_count' => $transactions->count(),
            'items_sold' => (int) $allItems->sum('quantity'),
            'average_sale' => $transactions->count()
                ? round($totalRevenue / $transactions->count(), 2)
                : 0,
            'total_cogs' => round($totalCogs, 2),
            'gross_profit' => $grossProfit,
            'gross_margin' => $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 2) : 0.0,
        ];
    }

    /**
     * Every product sold in the period, richest-first by quantity.
     * Used for the Best Selling Items table on the Sales Report page.
     */
    private function productBreakdown($allItems)
    {
        return $allItems
            ->groupBy('product_name')
            ->map(function ($items, $name) {
                $revenue = (float) $items->sum('subtotal');
                $cogs = (float) $items->whereNotNull('cogs')->sum('cogs');
                $profit = round($revenue - $cogs, 2);
                $quantity = (int) $items->sum('quantity');

                return [
                    'name' => $name,
                    'category' => optional($items->first()->product)->category ?? '—',
                    'quantity' => $quantity,
                    'unit_price' => $quantity > 0 ? round($revenue / $quantity, 2) : 0.0,
                    'total' => round($revenue, 2),
                    'cogs' => round($cogs, 2),
                    'gross_profit' => $profit,
                    'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
                    'has_incomplete_cost' => $items->contains(fn ($i) => $i->cogs === null),
                ];
            })
            ->values()
            ->sortByDesc('quantity')
            ->values();
    }

    private function paymentMethodSummary($transactions, float $totalRevenue)
    {
        return $transactions
            ->groupBy(fn ($t) => $t->payment_method ?? 'cash')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'label' => $method === 'gcash' ? 'GCash' : 'Cash',
                'transactions' => $group->count(),
                'total_sales' => round((float) $group->sum('total'), 2),
                'percentage' => $totalRevenue > 0
                    ? round(((float) $group->sum('total') / $totalRevenue) * 100, 2)
                    : 0.0,
            ])
            ->values()
            ->sortByDesc('total_sales')
            ->values();
    }

    private function orderTypeSummary($transactions)
    {
        return $transactions
            ->groupBy('order_type')
            ->map(fn ($group, $type) => [
                'order_type' => $type,
                'label' => $type === 'dine_in' ? 'Dine-in' : 'Take-out',
                'transactions' => $group->count(),
                'items_sold' => (int) $group->flatMap->items->sum('quantity'),
                'total_sales' => round((float) $group->sum('total'), 2),
            ])
            ->values()
            ->sortByDesc('total_sales')
            ->values();
    }

    /**
     * Rows for the Transaction Log sheet/section. Includes completed,
     * refunded, and voided transactions (spec section 13) with a Notes
     * column explaining the refund/void — but callers must only sum
     * "Completed" rows toward Total Sales.
     */
    private function transactionLogRows($transactions): array
    {
        return $transactions->map(fn ($t) => [
            '#TXN-'.str_pad((string) $t->order_number, 6, '0', STR_PAD_LEFT),
            $t->created_at->format('Y-m-d'),
            $t->created_at->format('g:i A'),
            $t->user?->name ?? 'Unknown',
            $t->order_number,
            $t->order_type === 'dine_in' ? 'Dine-in' : 'Take-out',
            $t->payment_method === 'gcash' ? 'GCash' : 'Cash',
            $t->gcash_reference_number ?? '—',
            (float) $t->amount_received,
            (float) $t->change,
            (float) $t->total,
            Str::headline($t->status),
            $this->transactionNote($t),
            $t->created_at->format('Y-m-d H:i:s'),
        ])->all();
    }

    /**
     * Refund/void reason shown in the Transaction Log's Notes column.
     * Empty for completed transactions.
     */
    private function transactionNote(Transaction $t): string
    {
        return match ($t->status) {
            'refunded' => trim('Refund: ₱'.number_format((float) ($t->refund_amount ?? 0), 2).' — '.($t->refund_reason ?? 'No reason given')),
            'voided' => trim('Void: '.($t->void_reason ?? 'No reason given')),
            default => '—',
        };
    }

    /**
     * Shared data builder for the Sales Report Excel + Word exports.
     * KPIs stay completed-only (single source of truth, matches the UI);
     * the Transaction Log additionally includes refunded/voided rows.
     *
     * @return array{0: array{period:string,generated_by:string,generated_date:string}, 1: array{summary:array,transactionLog:array}}
     */
    private function buildSalesReportData(Request $request): array
    {
        $period = $request->input('period', 'daily');
        $date = $request->input('date', now()->toDateString());

        // Completed-only — drives KPIs/summary, matching the UI exactly.
        $transactions = $this->salesPeriodQuery($period, $date)->orderBy('created_at')->get();
        $allItems = $transactions->flatMap->items;
        $summary = $this->summarizeSales($transactions, $allItems);

        // All statuses — the Transaction Log must show refunds/voids too.
        $logTransactions = $this->salesPeriodQuery($period, $date, allStatuses: true)
            ->orderBy('created_at')
            ->get();

        $meta = [
            'period' => ucfirst($period).' — '.$this->salesPeriodLabel($period, $date),
            'generated_by' => $request->user()?->name ?? 'Manager',
            'generated_date' => now()->format('M j, Y g:i A'),
        ];

        return [
            $meta,
            [
                'summary' => $summary,
                'transactionLog' => $this->transactionLogRows($logTransactions),
            ],
        ];
    }

    /**
     * Downloads the Sales Report (Transaction Log, with Total Sales) as .xlsx.
     */
    public function exportSales(Request $request): BinaryFileResponse
    {
        [$meta, $data] = $this->buildSalesReportData($request);

        $filename = 'JC66-Sales-Report-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new SalesReportExport($meta, $data), $filename);
    }

    /**
     * Downloads a single Sales Report section as .xlsx. Currently only
     * "transaction-log" exists as a section.
     */
    public function exportSalesSheet(Request $request, string $sheet): BinaryFileResponse
    {
        [$meta, $data] = $this->buildSalesReportData($request);

        [$export, $label] = match ($sheet) {
            'transaction-log' => [new TransactionLogSheet($meta, $data['transactionLog']), 'Transaction-Log'],
            default => abort(404, "Unknown report sheet [{$sheet}]."),
        };

        $filename = 'JC66-'.$label.'-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download($export, $filename);
    }

    /**
     * Downloads the Sales Report (Transaction Log, with Total Sales) as Word.
     */
    public function exportSalesWord(Request $request): BinaryFileResponse
    {
        [$meta, $data] = $this->buildSalesReportData($request);

        $filename = 'JC66-Sales-Report-'.now()->format('Y-m-d').'.docx';

        $path = (new SalesReportWordExport($meta, $data))->save();

        return response()
            ->download($path, $filename)
            ->deleteFileAfterSend(true);
    }

    /**
     * Downloads a single Sales Report section as Word. Currently only
     * "transaction-log" exists as a section.
     */
    public function exportSalesWordSection(Request $request, string $sheet): BinaryFileResponse
    {
        [$meta, $data] = $this->buildSalesReportData($request);

        $labels = [
            'transaction-log' => 'Transaction-Log',
        ];

        if (! isset($labels[$sheet])) {
            abort(404, "Unknown report section [{$sheet}].");
        }

        $filename = 'JC66-'.$labels[$sheet].'-'.now()->format('Y-m-d').'.docx';

        $path = (new SalesReportWordExport($meta, $data))->saveSection($sheet);

        return response()
            ->download($path, $filename)
            ->deleteFileAfterSend(true);
    }

    /**
     * Transaction History for the cashier POS interface — shows only the
     * transactions processed by the currently authenticated cashier (not
     * every cashier's sales; that broader view already exists for
     * Admin/Manager at history() / "Sales History").
     */
    public function cashierHistory(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'completed', 'refunded', 'voided'])],
        ]);

        $status = $validated['status'] ?? 'all';

        $query = Transaction::with('items')
            ->where('user_id', $request->user()->id)
            ->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $transactions = $query->paginate(15)->withQueryString();

        $transactions->getCollection()->transform(fn ($t) => [
            'id' => $t->id,
            'order_number' => $t->order_number,
            'transaction_no' => '#TXN-' . str_pad((string) $t->order_number, 6, '0', STR_PAD_LEFT),
            'created_at' => $t->created_at,
            'order_type' => $t->order_type,
            'customer_name' => $t->customer_name,
            'notes' => $t->notes,
            'payment_method' => $t->payment_method,
            'status' => $t->status,
            'total' => (float) $t->total,
            'amount_received' => (float) $t->amount_received,
            'change' => (float) $t->change,
            'gcash_reference_number' => $t->gcash_reference_number,
            'items' => $t->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'subtotal' => (float) $item->subtotal,
            ]),
        ]);

        return Inertia::render('cashier/Products/TransactionHistory', [
            'transactions' => $transactions,
            'filters' => ['status' => $status],
        ]);
    }

    public function history(Request $request)
    {
        $query = Transaction::with(['items', 'user:id,name'])
            ->latest();

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        $transactions = $query->paginate(20)->withQueryString();

        return Inertia::render('Sales/History', [
            'transactions' => $transactions,
            'filters' => $request->only(['date', 'status', 'payment_method']),
        ]);
    }
}