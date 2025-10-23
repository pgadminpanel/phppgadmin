# Translator Info

If you like phpPgAdmin, then why not translate it into your native language?

There are quite a large number of strings to be translated. Partial translations are better than no translations at all. As a rough guide, the strings are ordered from most important to least important in the language file. You can ask on the project's discussion forum if you don't know what a certain string means.

phpPgAdmin uses UTF-8 for translations. Always work with UTF-8 files when creating a new translation or editing an existing one.

## Create a new translation

1. Go to the `lang/` subdirectory.

2. Copy `english.php` to `yourlanguage.php`.

3. Update the comment at the top of the file. Put yourself as the language maintainer. Edit the `applang` variable and put your language's name in it, in your language. Edit the `applocale` and put your language code according to **BCP 47** (the modern successor to RFC 1766):

    - BCP 47 / RFC 5646: https://www.rfc-editor.org/rfc/rfc5646

    Basically, you just need to put your language code and optionally the country code separated by a `-`. Example for French (Canadian): `fr-CA`.

    References:

    - ISO 639 language codes: https://iso639-3.sil.org/
    - ISO 3166 country codes: https://www.iso.org/iso-3166-country-codes.html

4. Go through as much of the rest of the file as you wish, replacing the English strings with strings in your native language.

At this point you can send the `yourlanguage.php` file to the project and the maintainers will help with testing and recoding if necessary. Only do that if you find the rest of these steps too difficult.

## Add your language to phpPgAdmin

5. Edit `lang/translations.php` and add your language to the `$appLangFiles` array. Also add your language to the `$availableLanguages` array for browser auto-detection.

6. Send your contribution (the `lang/translations.php` entry and the `lang/yourlanguage.php` file) to the project:

    - Repository: https://github.com/orgs/pgadminpanel/discussions
    - Open an issue or pull request, or start a discussion on GitHub.

## Tools

There is a tool named `langcheck` in the `lang/` directory. To run it:

```bash
php langcheck <language>
```

It reports which strings are missing from your language file and which need to be deleted.

Thank you for your contribution — you have just made phpPgAdmin accessible to thousands more users!