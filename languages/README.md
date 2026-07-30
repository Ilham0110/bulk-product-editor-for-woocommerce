# Terjemahan

Folder ini dibaca WordPress karena header plugin menyatakan
`Domain Path: /languages`.

## Pola nama file

```
wc-bulk-editor.pot            template (semua string, tanpa terjemahan)
wc-bulk-editor-id_ID.po       terjemahan Indonesia (sumber, di-commit)
wc-bulk-editor-id_ID.mo       hasil kompilasi (di-ignore git)
```

`.mo` adalah yang benar-benar dimuat WordPress. File itu hasil kompilasi dari
`.po` dan sengaja tidak masuk repo — lihat `.gitignore`.

## Membuat .pot

Dengan WP-CLI:
```bash
wp i18n make-pot . languages/wc-bulk-editor.pot
```

Tanpa WP-CLI: pakai Poedit (aplikasi desktop), arahkan ke folder plugin.

## Catatan

Text domain `wc-bulk-editor` dipakai konsisten di seluruh kode dan selalu
sebagai string literal — syarat agar parser bisa menemukannya.

String untuk JavaScript ada di `i18n_strings()`, bukan di `admin.js`.
Placeholder JS memakai `{count}`, bukan `%d`.

Lihat `../docs/I18N.md` untuk detail.
