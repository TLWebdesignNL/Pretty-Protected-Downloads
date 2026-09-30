<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * One download button per file. See ../prettyprotecteddownloads.php for the variables.
 */

\defined('_JEXEC') or die;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="prettyprotecteddownloads prettyprotecteddownloads--buttons d-flex flex-wrap gap-2">
    <?php foreach ($downloads as $download) : ?>
        <?php $text = $download->button ?: $download->label; ?>
        <form action="<?php echo $e($actionUrl); ?>" method="post" class="d-inline-block">
            <?php echo $download->hidden; ?>
            <button type="submit" class="btn <?php echo $e($download->class . ' ' . $buttonClass); ?>"
                <?php if ($download->meta !== '') : ?>
                    title="<?php echo $e($download->meta); ?>"
                <?php endif; ?>>
                <?php if ($download->icon !== '') : ?>
                    <span class="<?php echo $e($download->icon); ?> me-1" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo $e($text); ?>
                <?php if ($text !== $download->label) : ?>
                    <span class="visually-hidden">, <?php echo $e($download->label); ?></span>
                <?php endif; ?>
                <?php if ($download->meta !== '') : ?>
                    <span class="visually-hidden">(<?php echo $e($download->meta); ?>)</span>
                <?php endif; ?>
            </button>
        </form>
    <?php endforeach; ?>
</div>
