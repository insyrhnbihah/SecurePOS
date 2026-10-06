# Reports PDF export

The Manager-only Reports page links to `reports_export.php` using normalized, applied filters. The endpoint calls the existing `requireManager()` guard, validates scalar UTF-8 input and filter/date allowlists, and rejects invalid categories. It uses the existing prepared queries in `config/reports_data.php`; those queries and calculations were moved unchanged from Reports.

`config/report_export_filters.php` provides validation and filter serialization. `config/reports_pdf.php` renders summaries and all applicable tables with tFPDF 1.33 and locally bundled DejaVu Sans fonts. No HTTP requests, external fonts, Composer, Python, or internet connection are required at runtime. tFPDF is LGPL-2.1; the original source, license, and font license are included in `vendor/tfpdf`. The library directory denies direct HTTP access. Font metric caches are generated locally and ignored by Git.

Exports download an A4 landscape PDF with selected filters, Kuala Lumpur generation time, logged-in name, repeated table headers and Page X of Y footers. Large tables paginate; empty result sets produce an explanatory message. Sales includes transactions and top items; Attendance includes monthly summaries and individual records.

Verification: `python tests/report_export_test.py` exercises real local database reports via an isolated localhost PHP test server. It checks matching data and summaries, filters, invalid input, and rejected anonymous/Cashier/Inventory Staff requests. PDF QA uses PyMuPDF, which is a development-only test dependency; a temporary installation under `tmp/pdfs/qa` is supported. `python tests/smoke_test.py` covers existing page rendering. Test PDFs and renders stay in ignored `tmp/pdfs`.
