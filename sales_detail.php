<?php
session_start();
require_once 'dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    http_response_code(403);
    exit('Access denied');
}

$sale_id = intval($_GET['id'] ?? 0);
if (!$sale_id) {
    echo '<p style="color:red">Invalid sale ID.</p>';
    exit();
}

// sales header
$res = $conn->query("
    SELECT s.*,
           CONCAT(c.first_name,' ',c.last_name) AS customer_name,
           c.contact_number,
           c.email AS customer_email,
           CONCAT(e.first_name,' ',e.last_name) AS processed_by_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN employee  e ON s.processed_by = e.employeeID
    WHERE s.sales_id = $sale_id
");
$sale = $res ? $res->fetch_assoc() : null;
if (!$sale) {
    echo '<p style="color:red">Sale not found.</p>';
    exit();
}

// items
$items_res = $conn->query("
    SELECT si.*, p.product_name
    FROM sales_items si
    LEFT JOIN products p ON si.product_id = p.product_id
    WHERE si.sales_id = $sale_id
");
$items = [];
while ($items_res && $r = $items_res->fetch_assoc())
    $items[] = $r;

// payments
$pay_res = $conn->query("SELECT * FROM payments WHERE sales_id = $sale_id ORDER BY payment_date DESC");
$payments = [];
while ($pay_res && $r = $pay_res->fetch_assoc())
    $payments[] = $r;

$total_paid = array_sum(array_column($payments, 'amount_paid'));
$balance = floatval($sale['final_amount']) - $total_paid;

$badge_map = ['Paid' => 'badge-paid', 'Partially Paid' => 'badge-partial', 'Unpaid' => 'badge-unpaid'];
$badge_class = $badge_map[$sale['status']] ?? 'badge-unpaid';
?>

<style>
    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
    }

    .detail-label {
        font-size: 11px;
        color: #888;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .detail-value {
        font-size: 14px;
        font-weight: 500;
    }

    .items-view-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .items-view-table th {
        background: #f9fafb;
        padding: 9px 12px;
        text-align: left;
        font-size: 11px;
        text-transform: uppercase;
        color: #888;
        border-bottom: 1px solid #e8e7e3;
    }

    .items-view-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f3f4f6;
    }

    .payment-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f3f4f6;
        font-size: 13px;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .badge-paid {
        background: #dcfce7;
        color: #166534;
    }

    .badge-unpaid {
        background: #fee2e2;
        color: #991b1b;
    }

    .badge-partial {
        background: #fef9c3;
        color: #854d0e;
    }

    .section-title {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #444;
        margin: 20px 0 10px;
    }

    .summary-box {
        background: #f9fafb;
        border-radius: 10px;
        padding: 16px;
        font-size: 13px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 5px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .summary-row:last-child {
        border: none;
        font-size: 15px;
        font-weight: 700;
        margin-top: 4px;
    }
</style>

<div class="detail-grid">
    <div>
        <div class="detail-label">Sale #</div>
        <div class="detail-value">#<?= str_pad($sale['sales_id'], 5, '0', STR_PAD_LEFT) ?></div>
    </div>
    <div>
        <div class="detail-label">Status</div>
        <div><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($sale['status']) ?></span></div>
    </div>
    <div>
        <div class="detail-label">Customer</div>
        <div class="detail-value"><?= htmlspecialchars($sale['customer_name'] ?? '—') ?></div>
        <?php if ($sale['contact_number']): ?>
            <div style="font-size:12px; color:#888;"><?= htmlspecialchars($sale['contact_number']) ?></div>
        <?php endif; ?>
    </div>
    <div>
        <div class="detail-label">Date</div>
        <div class="detail-value"><?= date('M d, Y · h:i A', strtotime($sale['sales_date'])) ?></div>
    </div>
    <div>
        <div class="detail-label">Processed By</div>
        <div class="detail-value"><?= htmlspecialchars($sale['processed_by_name'] ?? '—') ?></div>
    </div>
    <div>
        <div class="detail-label">Linked Job Order</div>
        <div class="detail-value">
            <?= $sale['job_order_id'] ? '#' . str_pad($sale['job_order_id'], 5, '0', STR_PAD_LEFT) : '—' ?>
        </div>
    </div>
</div>

<div class="section-title">Line Items</div>
<?php if (empty($items)): ?>
    <p style="color:#aaa; font-size:13px;">No items recorded.</p>
<?php else: ?>
    <table class="items-view-table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Description</th>
                <th style="text-align:center">Qty</th>
                <th style="text-align:right">Unit Price</th>
                <th style="text-align:right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <span style="background:<?= $item['item_type'] === 'Product' ? '#dbeafe' : '#dcfce7' ?>;
                             color:<?= $item['item_type'] === 'Product' ? '#1e40af' : '#166534' ?>;
                             padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600;">
                            <?= htmlspecialchars($item['item_type']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($item['description'] ?: $item['product_name'] ?? '—') ?></td>
                    <td style="text-align:center"><?= $item['quantity'] ?></td>
                    <td style="text-align:right">₱<?= number_format($item['unit_price'], 2) ?></td>
                    <td style="text-align:right; font-weight:600;">₱<?= number_format($item['subtotal'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div style="display:flex; gap:20px; margin-top:16px; align-items:flex-start; flex-wrap:wrap;">

    <div style="flex:1; min-width:220px;">
        <div class="section-title">Payment History</div>
        <?php if (empty($payments)): ?>
            <p style="color:#aaa; font-size:13px;">No payments yet.</p>
        <?php else: ?>
            <?php foreach ($payments as $p): ?>
                <div class="payment-row">
                    <div>
                        <div style="font-weight:500"><?= htmlspecialchars($p['payment_method']) ?></div>
                        <?php if ($p['reference_number']): ?>
                            <div style="font-size:11px; color:#888;">Ref: <?= htmlspecialchars($p['reference_number']) ?></div>
                        <?php endif; ?>
                        <div style="font-size:11px; color:#aaa;"><?= date('M d, Y h:i A', strtotime($p['payment_date'])) ?>
                        </div>
                    </div>
                    <div style="color:#16a34a; font-weight:700;">+₱<?= number_format($p['amount_paid'], 2) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="width:220px;">
        <div class="section-title">Summary</div>
        <div class="summary-box">
            <div class="summary-row"><span>Subtotal</span><span>₱<?= number_format($sale['total_amount'], 2) ?></span>
            </div>
            <div class="summary-row"><span>Discount</span><span
                    style="color:#dc2626;">-₱<?= number_format($sale['discount'], 2) ?></span></div>
            <div class="summary-row"><span>Total</span><span>₱<?= number_format($sale['final_amount'], 2) ?></span>
            </div>
            <div class="summary-row"><span>Paid</span><span
                    style="color:#16a34a;">₱<?= number_format($total_paid, 2) ?></span></div>
            <div class="summary-row">
                <span>Balance</span>
                <span style="color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>;">
                    ₱<?= number_format(max(0, $balance), 2) ?>
                </span>
            </div>
        </div>
    </div>
</div>