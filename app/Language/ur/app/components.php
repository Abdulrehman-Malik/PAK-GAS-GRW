<?php

/**
 * Urdu interface text: table controls (DataTables), sort labels, page titles,
 * option labels built from several parts, and names seeded into the database
 * (roles, permissions, expense categories).
 * Key = the exact English text; {0}, {1}... = parts that vary at runtime.
 * Used by UiLanguage (app/Libraries) and public/js/lpg-i18n.js. Do not duplicate keys across files.
 */
return [
    // Tables (DataTables)
    'Search:' => 'تلاش:',
    'Show' => 'دکھائیں',
    'entries' => 'اندراجات',
    'Previous' => 'پچھلا',
    'Next' => 'اگلا',
    'First' => 'پہلا',
    'Last' => 'آخری',
    'Processing...' => 'پروسیسنگ...',
    'No data available in table' => 'ٹیبل میں کوئی ڈیٹا دستیاب نہیں',
    'No matching records found' => 'کوئی مماثل ریکارڈ نہیں ملا',
    'Showing {0} to {1} of {2} entries' => '{2} میں سے {0} تا {1} اندراجات دکھائے جا رہے ہیں',
    '(filtered from {0} total entries)' => '(کل {0} اندراجات میں سے فلٹر شدہ)',
    '{0}: activate to sort column ascending' => '{0}: صعودی ترتیب کے لیے فعال کریں',
    '{0}: activate to sort column descending' => '{0}: نزولی ترتیب کے لیے فعال کریں',
    'Failed to fetch' => 'سرور سے رابطہ نہیں ہو سکا',

    // "Page — Branch" browser/tab titles
    '{0} — {1}' => '{0} — {1}',

    // Option and summary labels built from several parts
    '{0} — Available Stock: {1} KG ({2} cylinders)' => '{0} — دستیاب اسٹاک: {1} KG ({2} سلنڈرز)',
    '{0} — Filled — {1}' => '{0} — بھرا ہوا — {1}',
    '{0} — Empty — {1}' => '{0} — خالی — {1}',
    '{0} ({1} KG · {2} in shop)' => '{0} ({1} KG · دکان میں {2})',
    '{0} kg available' => '{0} kg دستیاب',
    'Filled {0}' => 'بھرا ہوا {0}',
    'Empty {0}' => 'خالی {0}',
    '{0} cylinder' => '{0} سلنڈر',
    '{0} KG gas' => '{0} KG گیس',
    '{0} deposit held' => '{0} ڈپازٹ رکھا گیا',
    '{0} cylinder{1}' => '{0} سلنڈر',
    '{0} user{1}' => '{0} صارف',

    // Roles (seeded)
    'Administrator' => 'ایڈمنسٹریٹر',
    'Manager' => 'منیجر',
    'Cashier' => 'کیشیئر',
    'Operations User' => 'آپریشنز صارف',
    'Full system access' => 'سسٹم تک مکمل رسائی',
    'Operational access' => 'آپریشنل رسائی',
    'POS and cash access' => 'POS اور کیش تک رسائی',
    'Dashboard, sales and receipts, inventory, customer ledger, cash, expenses and rates' => 'ڈیش بورڈ، فروخت اور رسیدیں، انوینٹری، کسٹمر لیجر، کیش، اخراجات اور ریٹس',

    // Permissions (seeded)
    'View dashboard' => 'ڈیش بورڈ دیکھیں',
    'Create POS sales' => 'POS فروخت بنائیں',
    'Void posted sales' => 'پوسٹ شدہ فروخت منسوخ کریں',
    'View sale history' => 'فروخت کی ہسٹری دیکھیں',
    'Manage customers' => 'کسٹمرز کا نظم کریں',
    'Manage suppliers' => 'سپلائرز کا نظم کریں',
    'Manage inventory' => 'انوینٹری کا نظم کریں',
    'Manage inventory controls' => 'انوینٹری کنٹرولز کا نظم کریں',
    'Manage gas wastage' => 'گیس کے ضیاع کا نظم کریں',
    'Manage LPG rates' => 'ایل پی جی ریٹس کا نظم کریں',
    'Manage purchases' => 'خریداریوں کا نظم کریں',
    'Manage expenses' => 'اخراجات کا نظم کریں',
    'Manage counter cash' => 'کاؤنٹر کیش کا نظم کریں',
    'Manage users' => 'صارفین کا نظم کریں',
    'View reports' => 'رپورٹس دیکھیں',
    'View audit log' => 'آڈٹ لاگ دیکھیں',
    'Create and restore database backups' => 'ڈیٹا بیس بیک اپ بنائیں اور بحال کریں',

    // Expense categories (seeded)
    'Bowser / Transport' => 'باؤزر / ٹرانسپورٹ',
    'Salary / Wages' => 'تنخواہ / اجرت',
    'Utilities' => 'یوٹیلیٹیز',
    'Maintenance' => 'مرمت و دیکھ بھال',
];
