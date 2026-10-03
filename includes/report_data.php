<?php
function getConsignmentReport(mysqli $conn, array $filters): array
{
    ensurePODSchema($conn);
    $where = ['1=1'];
    $from = $filters['from'] ?? ''; $to = $filters['to'] ?? ''; $search = trim($filters['search'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $where[] = "c.booking_date >= '" . $conn->real_escape_string($from) . "'";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $where[] = "c.booking_date <= '" . $conn->real_escape_string($to) . "'";
    foreach (['docket_tracking_status' => 'tracking', 'pod_priority' => 'priority'] as $column => $key) {
        $value = trim($filters[$key] ?? '');
        if ($value !== '') $where[] = "c.{$column} = '" . $conn->real_escape_string($value) . "'";
    }
    if ($search !== '') { $like = $conn->real_escape_string($search); $where[] = "(c.consignment_note LIKE '%{$like}%' OR c.billing_party_name LIKE '%{$like}%' OR c.consignee_name LIKE '%{$like}%')"; }
    $pod = $filters['pod'] ?? '';
    if ($pod === 'uploaded') $where[] = 'EXISTS (SELECT 1 FROM pod_files px WHERE px.consignment_id = c.id)';
    if ($pod === 'pending') $where[] = 'NOT EXISTS (SELECT 1 FROM pod_files px WHERE px.consignment_id = c.id)';
    $sql = "SELECT c.consignment_note, c.booking_date, c.billing_party_name, c.consignor_name, c.consignor_pin, c.consignee_name, c.consignee_pin,
                   o.city_name AS origin_city, o.pincode AS origin_pincode, d.city_name AS destination_city, d.pincode AS destination_pincode,
                   clr.rate_per_kg, clr.rate_per_piece, clr.rate_per_km,
                   c.no_of_pieces, c.actual_weight, c.charged_weight,
                   c.basic_freight, c.fuel_charge, c.dkt_charge, c.handling_charge, c.oda_charge, c.detention, c.misc_charge, c.other_charge, c.risk_charge,
                   c.sgst, c.cgst, c.igst, c.grand_total,
                   cc.company_name AS co_loader, c.docket_tracking_status, c.handover_date, c.delivery_date, c.remarks,
                   c.party_invoice_no AS bill_no, c.pod_priority, c.status,
                   CASE WHEN EXISTS (SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) THEN 'Yes' ELSE 'No' END AS pod_status_soft,
                   'Not Recorded' AS pod_status_hard,
                   (SELECT COUNT(*) FROM pod_files p WHERE p.consignment_id=c.id) AS pod_files
            FROM consignments c
            LEFT JOIN cities o ON o.id=c.origin_city_id
            LEFT JOIN cities d ON d.id=c.destination_city_id
            LEFT JOIN client_lane_rates clr ON clr.client_id = c.client_master_id AND clr.origin_city_id = c.origin_city_id AND clr.destination_city_id = c.destination_city_id
            LEFT JOIN courier_companies cc ON cc.id=c.courier_company_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY c.booking_date DESC, c.id DESC LIMIT 5000';
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
