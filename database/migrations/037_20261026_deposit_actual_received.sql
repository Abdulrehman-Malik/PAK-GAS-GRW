-- Perfect LPG - Security deposit shows the amount actually received
-- 1) sales.security_deposit_amount on Security Deposit invoices becomes the deposit actually received
--    (the sum of its customer_security_deposits hold entries), not the amount keyed in at POS.
-- 2) cylinder_custody.deposit_amount of cylinders still issued is that received amount shared across the
--    cylinders issued on the same invoice (last cylinder takes the rounding remainder).
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons, so comments must not contain one.

UPDATE sales s
SET s.security_deposit_amount = COALESCE((SELECT SUM(d.amount) FROM customer_security_deposits d WHERE d.sale_id = s.id AND d.entry_type = 'hold'), 0)
WHERE s.transaction_type = 'security_deposit';

UPDATE cylinder_custody cc
JOIN sales s ON s.id = cc.issue_sale_id AND s.transaction_type = 'security_deposit'
JOIN (SELECT issue_sale_id, COUNT(*) n, MAX(id) last_id FROM cylinder_custody GROUP BY issue_sale_id) k ON k.issue_sale_id = cc.issue_sale_id
SET cc.deposit_amount = IF(cc.id = k.last_id, ROUND(s.security_deposit_amount - ROUND(s.security_deposit_amount / k.n, 2) * (k.n - 1), 2), ROUND(s.security_deposit_amount / k.n, 2))
WHERE cc.status = 'issued';
