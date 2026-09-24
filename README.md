# Pretty Protected Downloads

Pretty Protected Downloads is a Joomla custom field for articles that offers files for download without ever giving them a public URL. Files are stored outside the web root, and every download goes through Joomla, which checks that the visitor may see the article, its category, the field and its field group before a single byte is sent.

Use it for member documents, meeting minutes, reports for a closed group, price lists for logged-in customers: anything that should follow the access level of the article it belongs to, rather than being one guessed or shared link away from anyone.

## Features

- A new field type, **Pretty Protected Downloads**, for articles: add as many files to a field as you like, each with its own button text, title, description, icon and button style.
- Upload straight from the article form. A file uploads as soon as it is chosen, with a progress bar; the article cannot be saved while an upload is still running.
- Files are stored **outside the web root**, or inside it in a folder closed with `.htaccess`.
- Every download is checked against the article's publishing state and dates, the article and category access levels, and the field and field group access levels.
- Every download button carries a short-lived token bound to the visitor's session, so a file can only be fetched from a page that visitor was allowed to see.
- Visitors download the file under the name it was uploaded with.
- Three layouts, **Buttons**, **Cards** and **List**, each with an optional file type and size, and each overridable from your template.
- Works inside **subform** fields.
- Allowed file types and a maximum file size. Scripts, web pages and SVG images are always refused, and every upload is inspected for hidden PHP the same way the Media Manager does it.
- Removing a file from an article deletes it from disk once the article is saved, unless another article, such as a copy, still uses it.
- A storage status on the plugin settings screen, and a button that deletes stored files no field uses any more.
- English and Dutch language files.

## Requirements

- Joomla 5 or 6.
- PHP 8.1 or newer.

## Installation

1. Download the latest release ZIP from the GitHub releases page.
2. In Joomla Administrator, go to **System** → **Install** → **Extensions**.
3. Upload and install the ZIP. The plugin is enabled on installation.
4. Go to **System** → **Plugins**, open **Fields - Pretty Protected Downloads** and choose where the files are stored (see below). Save, and check the status.
5. Go to **Content** → **Fields**, create a new field and choose the type **Pretty Protected Downloads**.
6. Edit an article, save it once if it is new, and add files.

Latest release:
https://github.com/TLWebdesignNL/Pretty-Protected-Downloads/releases

## Storage

### Outside the web root (recommended)

Enter the absolute server path of a folder next to your website folder, for example `/home/account/protected-downloads` when the site lives in `/home/account/public_html`. The folder is created on the first upload if it does not exist yet, so its parent must be writable by PHP.

Files outside the web root have no URL at all. This works on every web server.

The plugin refuses to use the website folder itself, any folder above it, or one of Joomla's own folders (`administrator`, `images`, `media`, …): closing one of those with `.htaccess` would take the site offline. A folder *inside* one of them, such as `images/protected`, is allowed.

### Inside the web root

When the hosting account gives PHP no writable folder outside the website, choose **Inside the web root, closed with .htaccess**. The folder defaults to `files/prettyprotecteddownloads` and is closed with an `.htaccess` file that denies all direct access.

> [!WARNING]
> `.htaccess` is only read by **Apache** and **LiteSpeed**. On **nginx**, **IIS**, or any server that ignores `.htaccess`, the files in this folder can be downloaded by anyone who knows or guesses a name. Block the folder in the server configuration yourself, for example on nginx:
>
> ```nginx
> location ^~ /files/prettyprotecteddownloads/ { deny all; }
> ```
>
> or store the files outside the web root. The status on the settings screen warns whenever the folder has a URL.

### Status

The **Storage** tab of the plugin settings shows the folder as last saved: whether it exists or can be created, whether it is writable, whether it lies inside the web root, and the largest upload that is in effect: the lower of the plugin setting and the server's PHP limits (`upload_max_filesize`, `post_max_size`).

For a folder inside the web root it also reports **Direct access**: the plugin asks the web server for the folder's own `index.html` and shows whether the server refused it. This is the real test, because an `.htaccess` file on disk means nothing to a server that does not read it. If it says *NOT blocked*, fix the server configuration before storing anything confidential.

## Configuration

### Plugin settings

| Setting | Description |
|---|---|
| Storage Location | Outside the web root, or inside it closed with `.htaccess`. |
| Folder | The storage folder. An absolute path outside the web root, or a path relative to the site root inside it. |
| Allowed File Types | Comma separated extensions editors may upload. Default: `pdf,doc,docx,odt,rtf,txt,csv,xls,xlsx,ods,ppt,pptx,odp,zip,jpg,jpeg,png,gif,webp,mp3,mp4`. |
| Maximum File Size (MB) | The largest upload. The server's PHP limit applies as well. |
| Download Button Lifetime (minutes) | How long a download button keeps working after the page was loaded. Default: 30. |

### Field options

| Option | Description |
|---|---|
| Display | **Buttons**: a row of download buttons. **Cards**: a responsive grid of Bootstrap cards with title and description. **List**: a compact list with title, type, size and a small button. |
| Show File Type and Size | Shows e.g. "PDF, 1.2 MB" with each download. |
| Card Classes | Extra classes for every card (Cards display only). |
| Button Classes | Extra classes for every download button of the field, e.g. `btn-sm w-100`. |

Each file in a field has its own **Button Text**, **Title**, **Description**, **Icon Classes** (e.g. `fa-solid fa-file-pdf`) and **Button Style** (defaults to `btn-primary`).

The field's own **Access** setting, and that of its field group, decide who can download, on top of the article and category access. Give the field an access level of *Registered* to show the files to logged-in users only, even on a public article.

## Template overrides

The display layouts can be overridden like any Joomla layout. Copy the one you want from the plugin to your template:

| Plugin file | Override location |
|---|---|
| `plugins/fields/prettyprotecteddownloads/tmpl/prettyprotecteddownloads/buttons.php` | `templates/YOUR_TEMPLATE/html/plg_fields_prettyprotecteddownloads/prettyprotecteddownloads/buttons.php` |
| `.../tmpl/prettyprotecteddownloads/cards.php` | `.../html/plg_fields_prettyprotecteddownloads/prettyprotecteddownloads/cards.php` |
| `.../tmpl/prettyprotecteddownloads/list.php` | `.../html/plg_fields_prettyprotecteddownloads/prettyprotecteddownloads/list.php` |

Each download in a layout is a small `<form method="post">`: keep the `$download->hidden` inputs inside it, and post it to `$actionUrl`. The variables available to a layout are listed at the top of `tmpl/prettyprotecteddownloads.php`.

## How a download is checked

A download is a `POST` to `index.php?option=com_ajax&group=fields&plugin=prettyprotecteddownloads&task=download` and is only served when all of these hold:

1. The Joomla form token of the visitor's session is valid.
2. The download token was issued to this session, for exactly this file, article and field, and has not expired.
3. The article is published, within its publish up and publish down dates, and its access level is one of the visitor's.
4. The category is published, and its access level is one of the visitor's.
5. The field is published, and its access level, and that of its field group, is one of the visitor's.
6. The file is listed in that field of that article, and its stored name matches the entry it belongs to.

Anything else sends the visitor back to the page with a message. The file is sent as an attachment, under the name it was uploaded with but always with the stored file's own extension, with a content type taken from that extension rather than sniffed from the bytes, with `X-Content-Type-Options: nosniff` and a sandboxing Content Security Policy, and is never cached by the browser.

The plugin never reads, lists or deletes anything in the storage folder that it did not write itself. Its files all end in the uuid they were given on upload, so even a folder shared with other files comes to no harm from the clean-up.

Editors upload through the same endpoint (`task=upload`), which requires the form token and edit permission on the article: `core.edit`, or `core.edit.own` on their own articles.

## Clean-up

Uploads become part of an article when the article is saved. A file that was uploaded but never saved, or that belonged to an article that has since been deleted, stays on disk until it is cleaned up. The **Storage** tab of the plugin settings counts these files and offers **Delete unused files**. A file is only counted as unused when no field of any article names it, and when it is more than a day old, so uploads for articles that are still being edited are never touched.

## Limitations

- **Articles only.** The access rules checked on download are those of articles and their categories. A field of this type in another context (contacts, users, categories) shows a notice instead of the upload control.
- **Page caching.** The download tokens are issued when a page is rendered. With the *System - Page Cache* plugin on, a cached page hands out tokens of another session, and its downloads fail. Exclude the pages with downloads from the page cache, or keep the cache off.
- **Article versions.** Restoring an older version of an article from its history brings back its file entries, but not files that were deleted in the meantime.
- **Uninstalling** leaves the stored files in their folder. They are your site's documents, not the plugin's.

## Development

The plugin has no build step and no Composer dependencies. To run the tests:

```bash
php tests/run.php
```

Each test file runs in its own process against a temporary site root, standing up just enough of Joomla's language and registry classes to exercise the helpers that decide which file a request may reach. The tests are excluded from the release archive.

To work on the plugin inside a Joomla installation, symlink the repository to `plugins/fields/prettyprotecteddownloads` and `media/` to `media/plg_fields_prettyprotecteddownloads`, then use **System** → **Discover**.

## Releases and Updates

Push a tag named `V{version}` (e.g. `V1.0.0`) after updating the version in `prettyprotecteddownloads.xml`, `media/joomla.asset.json` and `changelog.xml`. The release workflow adds the version to `updates.xml` with the SHA-256 of the release archive and creates the GitHub release with notes taken from `changelog.xml`.

The plugin includes a Joomla update server:

```text
https://raw.githubusercontent.com/TLWebdesignNL/Pretty-Protected-Downloads/main/updates.xml
```

Release changelog:

```text
https://raw.githubusercontent.com/TLWebdesignNL/Pretty-Protected-Downloads/main/changelog.xml
```

## Links

- GitHub: https://github.com/TLWebdesignNL/Pretty-Protected-Downloads
- Releases: https://github.com/TLWebdesignNL/Pretty-Protected-Downloads/releases
- Issues: https://github.com/TLWebdesignNL/Pretty-Protected-Downloads/issues
- TLWebdesign: https://tlwebdesign.nl/

## License

GNU General Public License version 2 or later.
