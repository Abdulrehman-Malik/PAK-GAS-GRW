# Localization (English / Urdu)

The whole app can be switched between English and Urdu. English is the source language; Urdu is
applied on top. The choice is stored in a cookie (`lpg_lang`, one year) and follows the user from the
sign-in screen into every page.

## How it works

| Piece | Role |
| --- | --- |
| `app/Libraries/UiLanguage.php` | Supported languages, cookie whitelist, dictionary loading, date/time formatting |
| `app/Controllers/Language.php` | `GET language/{code}` stores the choice; `GET i18n/{code}.js` serves the dictionary (cached for a year per version) |
| `app/Language/ur/Login.php`, `en/Login.php` | Sign-in screen copy (rendered on the server, switchable without reload) |
| `app/Language/ur/app/*.php` | Urdu text for the rest of the app: **key = the exact English text** |
| `public/js/lpg-i18n.js` | Swaps English text for Urdu in the page, in dynamically built content, tables and dialogs |
| `public/css/lpg-rtl.css` | Right-to-left layout and Urdu typography (loaded only in Urdu) |
| `app/Views/partials/i18n_head.php`, `i18n_foot.php`, `language_switch.php` | Shared includes used by the layout, receipt and setup pages |

English users load none of the Urdu files. Views, controllers and business logic are not changed by
translation: text that has no entry is simply shown in English.

## Adding or changing text

1. Write the English text in the view/controller as usual.
2. Add the same English text as a key in a file under `app/Language/ur/app/` with its Urdu value.
   * Key = the text exactly as it appears (whitespace is collapsed before matching).
   * Parts that change at runtime (amounts, names, counts) are `{0}`, `{1}`... in both key and value.
     `'Sale {0} voided successfully.' => 'فروخت {0} کامیابی سے منسوخ ہو گئی۔'`
   * Text that must stay English (codes, units, brand) maps to itself.
3. Run `php vendor/bin/phpunit`. `AppDictionaryTest` fails and lists every English string in views,
   view scripts and controller/service messages that still has no Urdu entry.

An exact entry always wins over a `{n}` pattern; patterns are tried longest first. A `{n}` that follows
a letter (`user{1}`) only matches a short plural ending.

Text stored in the database (customer names, notes you typed) is data and is not translated. Roles,
permissions, expense categories and the notes the system writes into ledgers are covered.

## Adding another language

1. Add it to `META` in `UiLanguage` (name, direction, date and time locales).
2. Add `app/Language/{code}/Login.php` and `app/Language/{code}/app/*.php`.
3. The tests require the same keys as English for the sign-in screen and full coverage for the app.
