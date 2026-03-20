<?php
session_start();
require_once 'dbconnection.php';

// Check if user is logged in
if (!isset($_SESSION['employeeID'])) {
    header("Location: index.php?error=Please log in first");
    exit();
}

// Get sale ID from URL
$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sale_id <= 0) {
    die("Invalid invoice ID.");
}

// Fetch sale details
$sale_query = $conn->query("
    SELECT s.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.contact_number,
           c.email,
           c.address,
           CONCAT(e.first_name, ' ', e.last_name) AS processed_by_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN employee e ON s.processed_by = e.employeeID
    WHERE s.sales_id = $sale_id
");

$sale = $sale_query->fetch_assoc();

if (!$sale) {
    die("Sale record not found.");
}

// Fetch sale items
$items_query = $conn->query("
    SELECT si.*, 
           p.product_name
    FROM sales_items si
    LEFT JOIN products p ON si.product_id = p.product_id
    WHERE si.sales_id = $sale_id
");

$items = [];
while ($row = $items_query->fetch_assoc()) {
    $items[] = $row;
}

// Fetch payment history
$payments_query = $conn->query("
    SELECT * FROM payments 
    WHERE sales_id = $sale_id 
    ORDER BY payment_date DESC
");

$payments = [];
while ($row = $payments_query->fetch_assoc()) {
    $payments[] = $row;
}

$total_paid = floatval($sale['total_paid'] ?? 0);
$balance = floatval($sale['final_amount']) - $total_paid;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= str_pad($sale_id, 5, '0', STR_PAD_LEFT) ?> — AutoBert</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            padding: 40px 20px;
        }
        
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: #fff;
            padding: 30px 40px;
            text-align: center;
        }
        
        .invoice-header h1 {
            font-size: 28px;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }
        
        .invoice-header .tagline {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .shop-info {
            background: #f8fafc;
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
            color: #334155;
        }
        
        .shop-info div {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .invoice-body {
            padding: 30px 40px;
        }
        
        .invoice-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .invoice-number {
            font-size: 24px;
            font-weight: 700;
            color: #1e3c72;
        }
        
        .invoice-status {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-partial {
            background: #fef9c3;
            color: #854d0e;
        }
        
        .status-paid {
            background: #dcfce7;
            color: #166534;
        }
        
        .bill-to {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .bill-to h3 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 12px;
        }
        
        .bill-to p {
            margin: 5px 0;
            font-size: 14px;
            color: #1e293b;
        }
        
        .customer-name {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .items-table th {
            background: #f1f5f9;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        
        .items-table .item-description {
            font-weight: 500;
        }
        
        .summary {
            margin-top: 30px;
            text-align: right;
        }
        
        .summary-row {
            display: flex;
            justify-content: flex-end;
            gap: 30px;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .summary-row.total {
            border-top: 2px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 12px;
            font-size: 18px;
            font-weight: 700;
            color: #1e3c72;
        }
        
        .summary-row.total .label {
            font-weight: 600;
        }
        
        .payment-history {
            margin-top: 30px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
        }
        
        .payment-history h3 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 12px;
        }
        
        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .payment-row:last-child {
            border-bottom: none;
        }
        
        .payment-method {
            font-weight: 500;
            color: #334155;
        }
        
        .footer {
            background: #f8fafc;
            padding: 20px 40px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #64748b;
        }
        
        .action-buttons {
            padding: 20px 40px;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        @media print {
            body {
                background: #fff;
                padding: 0;
                margin: 0;
            }
            .action-buttons {
                display: none;
            }
            .invoice-container {
                box-shadow: none;
                border-radius: 0;
            }
            .invoice-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <h1>AUTOBERT</h1>
            <div>REPAIR SHOP & BATTERIES</div>
            <div class="tagline">Quality Service You Can Trust</div>
        </div>
        
        <div class="shop-info">
            <div><i class="bi bi-geo-alt"></i> Across 7/11 Tahimik Avenue, Davao City</div>
            <div><i class="bi bi-telephone"></i> 09129253744</div>
            <div><i class="bi bi-envelope"></i> AlbertBoctot30@gmail.com</div>
        </div>
        
        <div class="invoice-body">
            <div class="invoice-title">
                <div class="invoice-number">SALES INVOICE #<?= str_pad($sale_id, 5, '0', STR_PAD_LEFT) ?></div>
                <div>
                    <span class="invoice-status status-<?= strtolower(str_replace(' ', '-', $sale['status'])) ?>">
                        <?= htmlspecialchars($sale['status']) ?>
                    </span>
                </div>
            </div>
            
            <div class="bill-to">
                <h3>BILL TO</h3>
                <div class="customer-name"><?= htmlspecialchars($sale['customer_name'] ?? '—') ?></div>
                <?php if (!empty($sale['address'])): ?>
                    <p><?= htmlspecialchars($sale['address']) ?></p>
                <?php endif; ?>
                <?php if (!empty($sale['contact_number'])): ?>
                    <p><i class="bi bi-telephone"></i> <?= htmlspecialchars($sale['contact_number']) ?></p>
                <?php endif; ?>
                <?php if (!empty($sale['email'])): ?>
                    <p><i class="bi bi-envelope"></i> <?= htmlspecialchars($sale['email']) ?></p>
                <?php endif; ?>
                <p><strong>Invoice Date:</strong> <?= date('F d, Y', strtotime($sale['sales_date'])) ?></p>
                <?php if ($sale['job_order_id']): ?>
                    <p><strong>Job Order #:</strong> <?= str_pad($sale['job_order_id'], 5, '0', STR_PAD_LEFT) ?></p>
                <?php endif; ?>
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($items as $item): 
                        $display_name = !empty($item['product_name']) ? $item['product_name'] : $item['description'];
                    ?>
                        <tr>
                            <td><?= $counter++ ?></td>
                            <td class="item-description"><?= htmlspecialchars($display_name) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td>₱<?= number_format($item['unit_price'], 2) ?></td>
                            <td>₱<?= number_format($item['subtotal'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span>₱<?= number_format($sale['total_amount'], 2) ?></span>
                </div>
                <?php if ($sale['discount'] > 0): ?>
                    <div class="summary-row">
                        <span>Discount:</span>
                        <span>- ₱<?= number_format($sale['discount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="summary-row total">
                    <span>Total Amount:</span>
                    <span>₱<?= number_format($sale['final_amount'], 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Amount Paid:</span>
                    <span style="color: #10b981;">₱<?= number_format($total_paid, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Balance Due:</span>
                    <span style="color: <?= $balance > 0 ? '#dc2626' : '#10b981' ?>; font-weight: 600;">
                        ₱<?= number_format($balance, 2) ?>
                    </span>
                </div>
            </div>
            
            <?php if (!empty($payments)): ?>
                <div class="payment-history">
                    <h3>PAYMENT HISTORY</h3>
                    <?php foreach ($payments as $payment): ?>
                        <div class="payment-row">
                            <div>
                                <span class="payment-method"><?= htmlspecialchars($payment['payment_method']) ?></span>
                                <span style="color:#64748b; margin-left:8px;"><?= date('M d, Y h:i A', strtotime($payment['payment_date'])) ?></span>
                            </div>
                            <div style="font-weight:600; color:#10b981;">₱<?= number_format($payment['amount_paid'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="payment-info" style="margin-top: 20px; padding: 16px; background: #f1f5f9; border-radius: 8px;">
                <h3 style="font-size: 12px; color: #475569; margin-bottom: 8px;">PAYMENT INFORMATION</h3>
                <div style="font-size: 12px; color: #334155; display: flex; flex-wrap: wrap; gap: 16px;">
                    <div><strong>GCash:</strong> 09129253744 (AutoBert)</div>
                    <div><strong>Bank:</strong> BDO - Account Name: AutoBert Repair Shop</div>
                    <div><strong>Account No.:</strong> 1234-5678-90</div>
                </div>
            </div>
            
            <div class="terms" style="margin-top: 20px; font-size: 11px; color: #64748b; text-align: center; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <p>This invoice is due upon receipt. A service warranty of 30 days applies to all labor and parts.</p>
                <p>Please quote invoice number when making payment.</p>
            </div>
        </div>
        
        <div class="footer">
            <p>THANK YOU FOR CHOOSING AUTOBERT!</p>
            <p>Your trusted partner for quality automotive repair and battery solutions.</p>
        </div>
        
        <div class="action-buttons">
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print / Save as PDF
            </button>
            <a href="sales.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Sales
            </a>
        </div>
    </div>
    
    <!-- Add Bootstrap Icons for print-friendly view -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <script>
        // Auto-trigger print dialog if ?print parameter is present
        if (window.location.search.includes('print=1')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
    </script>
</body>
</html>