<?php

/**
 * Urdu interface text: labels that views build inside PHP code (menu groups, status words,
 * form titles). Key = the exact English text; {0}, {1}... = parts that vary at runtime.
 * Used by UiLanguage (app/Libraries) and public/js/lpg-i18n.js. Do not duplicate keys across files.
 */
return [
    // Sidebar menu groups
    'Cash & Expenses'  => 'کیش اور اخراجات',
    'Configuration'    => 'ترتیبات',
    'Parties & Ledgers' => 'پارٹیاں اور لیجرز',
    'Purchasing'       => 'خریداری',
    'Reporting & Audit' => 'رپورٹنگ اور آڈٹ',
    'Sales & Receipts' => 'فروخت اور رسیدیں',
    'Gas Wastage'      => 'گیس کا ضیاع',
    'Inventory Controls' => 'انوینٹری کنٹرولز',

    // Migration notices in the app header
    'Database migration status could not be verified. Please log off and check the migration status from the login page.' => 'ڈیٹا بیس مائیگریشن کی حالت کی تصدیق نہیں ہو سکی۔ براہِ کرم لاگ آف کریں اور لاگ اِن صفحے سے مائیگریشن کی حالت چیک کریں۔',
    'Database migrations are pending. Please log off and execute the pending migrations from the login page.' => 'ڈیٹا بیس مائیگریشنز زیرِ التوا ہیں۔ براہِ کرم لاگ آف کریں اور لاگ اِن صفحے سے زیرِ التوا مائیگریشنز چلائیں۔',
    'Database migrations are pending. Please log off and execute the pending migrations from the login page. Pending: {0}.' => 'ڈیٹا بیس مائیگریشنز زیرِ التوا ہیں۔ براہِ کرم لاگ آف کریں اور لاگ اِن صفحے سے زیرِ التوا مائیگریشنز چلائیں۔ زیرِ التوا: {0}۔',

    // Master-data forms
    'Activate'         => 'فعال کریں',
    'Deactivate'       => 'غیر فعال کریں',
    'Inactive'         => 'غیر فعال',
    'Allowed'          => 'اجازت ہے',
    'Cash Only'        => 'صرف نقد',
    'Create User'      => 'صارف بنائیں',
    'Edit Customer'    => 'کسٹمر میں ترمیم',
    'Edit Supplier'    => 'سپلائر میں ترمیم',
    'Edit User'        => 'صارف میں ترمیم',
    'Update Customer'  => 'کسٹمر اپ ڈیٹ کریں',
    'Update Supplier'  => 'سپلائر اپ ڈیٹ کریں',
    'Update User'      => 'صارف اپ ڈیٹ کریں',
    'Save Customer'    => 'کسٹمر محفوظ کریں',
    'Save Supplier'    => 'سپلائر محفوظ کریں',
    'Vehicle No'       => 'گاڑی نمبر',
    'Sort Order'       => 'ترتیب',
    'Tare Weight KG'   => 'ٹیئر وزن KG',
    'Role permissions' => 'کردار کی اجازتیں',
    'Enter SMTP password' => 'SMTP پاس ورڈ درج کریں',
    'Leave blank to keep current password' => 'موجودہ پاس ورڈ رکھنے کے لیے خالی چھوڑ دیں',

    // Inventory, rates, ledgers, receipts
    'All Cylinders'    => 'تمام سلنڈرز',
    'Amount due'       => 'واجب الادا رقم',
    'Company Stock'    => 'کمپنی کا اسٹاک',
    'Complete lifecycle history.' => 'مکمل لائف سائیکل ہسٹری۔',
    'Current date history. Use All History for the complete lifecycle.' => 'موجودہ تاریخ کی ہسٹری۔ مکمل لائف سائیکل کے لیے "مکمل ہسٹری" استعمال کریں۔',
    'Customer Custody —' => 'کسٹمر کی تحویل —',
    'Customer Receipt' => 'کسٹمر رسید',
    'Customer credit / advance' => 'کسٹمر کریڈٹ / ایڈوانس',
    'Cylinder Return / Deposit Refund' => 'سلنڈر کی واپسی / ڈپازٹ ریفنڈ',
    'Empty Cylinder Price' => 'خالی سلنڈر کی قیمت',
    'Empty Cylinder Sale' => 'خالی سلنڈر کی فروخت',
    'Empty physical cylinders' => 'خالی فزیکل سلنڈرز',
    'Filled Cylinder Sale' => 'بھرے ہوئے سلنڈر کی فروخت',
    'Full Filled Cylinder' => 'مکمل بھرا ہوا سلنڈر',
    'Partially Filled Cylinder' => 'جزوی بھرا ہوا سلنڈر',
    'Gas / KG'         => 'گیس / KG',
    'Gas / Refill'     => 'گیس / ری فل',
    'Gas Refill'       => 'گیس ری فل',
    'Gas Sale / Refill' => 'گیس کی فروخت / ری فل',
    'Mixed Transaction' => 'ملی جلی ٹرانزیکشن',
    'Security Deposit / Cylinder Custody' => 'سیکیورٹی ڈپازٹ / سلنڈر کی تحویل',
    'Thank you'        => 'شکریہ',
    'Unknown'          => 'نامعلوم',
    'Unknown user'     => 'نامعلوم صارف',

    // Setup and settings
    'No — it will be created' => 'نہیں — یہ بنایا جائے گا',
    'Yes'              => 'ہاں',
    'System Default'   => 'سسٹم ڈیفالٹ',

    // Names that stay as they are (brand, font names)
    'Perfect LPG'      => 'Perfect LPG',
    'Arial'            => 'Arial',
    'Georgia'          => 'Georgia',
    'Tahoma'           => 'Tahoma',
    'Times New Roman'  => 'Times New Roman',
    'Trebuchet MS'     => 'Trebuchet MS',
    'Verdana'          => 'Verdana',
    'Arial,sans-serif' => 'Arial,sans-serif',
    'Georgia,serif'    => 'Georgia,serif',
    'Tahoma,sans-serif' => 'Tahoma,sans-serif',
    'Verdana,sans-serif' => 'Verdana,sans-serif',
];
