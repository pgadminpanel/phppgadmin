# Translators

Please read the [TRANSLATORS](../TRANSLATORS.md) file in the project root before contributing translations.

## Check your translation

Run the language check script from the `lang` directory:

```bash
cd lang
php ./langcheck <language>
# example: php ./langcheck french
```

## Synchronize with `english.php`

To synchronize your translation with the current `english.php` strings:

```bash
cd lang
php ./synch.php <language>
# example: php ./synch.php polish
```

If you have questions about translation conventions or string meanings, consult the `TRANSLATORS` file or open an issue.
