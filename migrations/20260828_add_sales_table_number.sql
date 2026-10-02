-- SecurePOS Step 17: record a table number for Dine In sales.
-- Existing and Take Away sales retain NULL table numbers.
ALTER TABLE sales
    ADD COLUMN table_number VARCHAR(8) NULL DEFAULT NULL
    AFTER order_type;
