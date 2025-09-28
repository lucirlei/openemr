<?php

/**
 * Simple watermark utilities used by the patient media workflow.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @copyright Copyright (c) 2024 OpenAI
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\ImageUtilities;

class Watermark
{
    /**
     * Applies a textual watermark to an image on disk. When the GD extension is unavailable the function will gracefully
     * fall back to copying the original file to the destination location.
     */
    public static function applyTextWatermark(string $sourcePath, string $destinationPath, string $text, array $options = []): bool
    {
        if (!extension_loaded('gd')) {
            if ($sourcePath === $destinationPath) {
                return true;
            }
            return copy($sourcePath, $destinationPath);
        }

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            if ($sourcePath === $destinationPath) {
                return false;
            }
            return copy($sourcePath, $destinationPath);
        }

        $type = $imageInfo[2];
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => null,
        };

        if (!$image) {
            if ($sourcePath === $destinationPath) {
                return false;
            }
            return copy($sourcePath, $destinationPath);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $padding = isset($options['padding']) ? max(0, (int)$options['padding']) : 14;
        $font = isset($options['font']) ? (int)$options['font'] : 3; // built-in font index
        $font = max(1, min(5, $font));
        $alpha = isset($options['alpha']) ? max(0, min(127, (int)$options['alpha'])) : 80;
        $colorArray = $options['color'] ?? [255, 255, 255];
        $color = imagecolorallocatealpha(
            $image,
            (int)($colorArray[0] ?? 255),
            (int)($colorArray[1] ?? 255),
            (int)($colorArray[2] ?? 255),
            $alpha
        );

        $lengthFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
        $textWidth = imagefontwidth($font) * $lengthFn($text);
        $textHeight = imagefontheight($font);
        $x = max(0, $width - $textWidth - $padding);
        $y = max(0, $height - $textHeight - $padding);

        imagestring($image, $font, $x, $y, $text, $color);

        if ($type === IMAGETYPE_PNG) {
            imagesavealpha($image, true);
        }

        $saved = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $destinationPath, $options['quality'] ?? 90),
            IMAGETYPE_PNG => imagepng($image, $destinationPath, $options['compression'] ?? 6),
            IMAGETYPE_GIF => imagegif($image, $destinationPath),
            default => false,
        };

        imagedestroy($image);
        return (bool)$saved;
    }
}
