-- SecurePOS Step 15: classify new POS sales while preserving unknown history.
-- Existing rows remain NULL because their original order type was not recorded.
ALTER TABLE sales
    ADD COLUMN order_type ENUM('Dine In', 'Take Away') NULL DEFAULT NULL
    AFTER payment_method;
