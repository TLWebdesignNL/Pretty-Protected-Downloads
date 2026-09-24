<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Covers the stored field value: what an entry may point at, which files a save
 * removes, and finding files inside subforms.
 */

require_once __DIR__ . '/bootstrap.php';

use TLWeb\Plugin\Fields\Prettyprotecteddownloads\Helper\Entries;

$uuid  = Entries::uuid();
$other = Entries::uuid();
$file  = 'annual-report-' . $uuid . '.pdf';

group('Uuids and stored names');
check('a new uuid is a version 4 uuid', preg_match(Entries::UUID, $uuid) === 1 && $uuid[14] === '4');
check('two uuids differ', $uuid !== $other);
check('the stored name keeps a readable base and ends in the uuid', Entries::storedName('Annual Report.PDF', $uuid) === $file);
check('anything but letters, digits, _ and - is folded to a dash', Entries::storedName('Jaarverslag (définitief) 2026!.pdf', $uuid) === 'jaarverslag-d-finitief-2026-' . $uuid . '.pdf');
check('a name of only symbols falls back to "file"', Entries::storedName('%%%.pdf', $uuid) === 'file-' . $uuid . '.pdf');
check('a long base is cut to 80 characters', strlen(explode('-' . $uuid, (string) Entries::storedName(str_repeat('a', 300) . '.pdf', $uuid))[0]) === 80);
check('a file without an extension is refused', Entries::storedName('README', $uuid) === null);
check('only the last extension counts', Entries::storedName('invoice.php.pdf', $uuid) === 'invoice-php-' . $uuid . '.pdf');

group('What an entry may point at');
check('a stored name belongs with its own uuid', Entries::belongsTogether($uuid, $file));
check('but not with another uuid', !Entries::belongsTogether($other, $file));
check('a path is never a stored name', !Entries::belongsTogether($uuid, '../' . $file));
check('nor a name with a second dot', !Entries::belongsTogether($uuid, 'x.php-' . $uuid . '.pdf'));
check('nor a uuid that is not one', !Entries::belongsTogether('abc', 'x-abc.pdf'));

group('Cleaning an entry from the form');
$entry = Entries::normalise(['uuid' => " $uuid ", 'filename' => $file, 'original' => 'Annual Report.pdf', 'title' => ' Report ', 'file_control' => 'x']);
check('a valid row is kept, trimmed', $entry !== null && $entry['uuid'] === $uuid && $entry['title'] === 'Report');
check('fields that are not part of an entry are dropped', !isset($entry['file_control']));
check('every text key is present', array_keys($entry) === ['uuid', 'filename', 'original', 'button', 'title', 'description', 'icon', 'class']);
check('a row without a file is dropped', Entries::normalise(['uuid' => '', 'filename' => '', 'title' => 'x']) === null);
check('a row pointing at another entry\'s file is dropped', Entries::normalise(['uuid' => $other, 'filename' => $file]) === null);
check('an uploaded name loses its directory', Entries::normalise(['uuid' => $uuid, 'filename' => $file, 'original' => '..\\..\\etc/passwd.pdf'])['original'] === 'passwd.pdf');
check('and its quotes and control characters', Entries::normalise(['uuid' => $uuid, 'filename' => $file, 'original' => "a\"b\r\nc.pdf"])['original'] === 'abc.pdf');

group('Decoding');
check('JSON decodes to a list', count(Entries::decode(json_encode([['uuid' => $uuid]]))) === 1);
check('an array passes through', count(Entries::decode([['uuid' => $uuid], 'junk'])) === 1);
check('garbage decodes to nothing', Entries::decode('{not json') === [] && Entries::decode(null) === [] && Entries::decode(42) === []);

group('The download name');
check('the uploaded name is used when there is one', Entries::downloadName(['uuid' => $uuid, 'filename' => $file, 'original' => 'Annual Report 2026.pdf']) === 'Annual Report 2026.pdf');
check('otherwise the stored name without its uuid', Entries::downloadName(['uuid' => $uuid, 'filename' => $file]) === 'annual-report.pdf');
check('an uploaded name with another extension gets the stored one', Entries::downloadName(['uuid' => $uuid, 'filename' => $file, 'original' => 'report.html']) === 'report.html.pdf');
check('extension case does not count as another extension', Entries::downloadName(['uuid' => $uuid, 'filename' => $file, 'original' => 'Report.PDF']) === 'Report.PDF');

group('Files a save removes');
$a = ['uuid' => $uuid, 'filename' => $file];
$b = ['uuid' => $other, 'filename' => 'b-' . $other . '.pdf'];
check('a removed entry gives up its file', Entries::removedFilenames([$a, $b], [$b]) === [$file]);
check('an edited entry keeps its file', Entries::removedFilenames([$a], [$a + ['title' => 'New']]) === []);
check('a reordered list removes nothing', Entries::removedFilenames([$a, $b], [$b, $a]) === []);
check('emptying the field removes all', count(Entries::removedFilenames([$a, $b], [])) === 2);

group('Subforms');
$subform = json_encode([
    'row0' => ['field12' => json_encode([$a]), 'field13' => 'text'],
    'row1' => ['field12' => [$b]],
]);
check('entries are gathered across rows, JSON or not', count(Entries::fromSubform($subform, 'field12')) === 2);
check('other children are ignored', Entries::fromSubform($subform, 'field13') === []);
$params = ['options' => ['option0' => ['customfield' => '12'], 'option1' => ['customfield' => '13']]];
check('only the children that are Pretty Protected Downloads fields are found', Entries::subformChildIds(json_encode($params), [12 => true]) === [12]);
check('a subform without options has none', Entries::subformChildIds('{}', [12 => true]) === []);

group('Every filename in a value');
$names = Entries::filenamesIn($subform);
sort($names);
$expected = [$file, 'b-' . $other . '.pdf'];
sort($expected);
check('found in rows and in JSON strings inside rows', $names === $expected);
check('a value that is plain text has none', Entries::filenamesIn('just some text') === []);
check('a "filename" that is a path is not taken', Entries::filenamesIn([['filename' => '../../configuration.php']]) === []);
check('nor one this plugin did not write', Entries::filenamesIn([['filename' => 'configuration.php']]) === []);

finish();
