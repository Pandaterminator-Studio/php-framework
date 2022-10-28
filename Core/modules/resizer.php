<?php

namespace Core\modules;
class resizer
{

    function Resize(
        string $imagePath = '',
        string $newPath = '',
        int    $newWidth = 0,
        int    $newHeight = 0,
        string $outExt = 'DEFAULT'
    ): string
    {
        if (!$newPath or !file_exists($imagePath)) {
            return false;
        }

        $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP];
        $type = exif_imagetype($imagePath);

        if (!in_array($type, $types)) {
            return false;
        }

        list ($width, $height) = getimagesize($imagePath);

        $outBool = in_array($outExt, [image_allowed_files]);

        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($imagePath);
                if (!$outBool) $outExt = 'jpg';
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($imagePath);
                if (!$outBool) $outExt = 'png';
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($imagePath);
                if (!$outBool) $outExt = 'gif';
                break;
            case IMAGETYPE_BMP:
                $image = imagecreatefrombmp($imagePath);
                if (!$outBool) $outExt = 'bmp';
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($imagePath);
                if (!$outBool) $outExt = 'webp';
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        //TRANSPARENT BACKGROUND
        $color = imagecolorallocatealpha($newImage, 0, 0, 0, 127); //fill transparent back
        imagefill($newImage, 0, 0, $color);
        imagesavealpha($newImage, true);

        //ROUTINE
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Rotate image on iOS
        if (function_exists('exif_read_data') && $exif = exif_read_data($imagePath, 'IFD0')) {
            if (isset($exif['Orientation']) && isset($exif['Make']) && !empty($exif['Orientation']) && preg_match('/(apple|ios|iphone)/i', $exif['Make'])) {
                switch ($exif['Orientation']) {
                    case 8:
                        if ($width > $height) $newImage = imagerotate($newImage, 90, 0);
                        break;
                    case 3:
                        $newImage = imagerotate($newImage, 180, 0);
                        break;
                    case 6:
                        $newImage = imagerotate($newImage, -90, 0);
                        break;
                }
            }
        }

        switch (true) {
            case in_array($outExt, ['jpg', 'jpeg']):
                $success = imagejpeg($newImage, $newPath);
                break;
            case $outExt === 'png':
                $success = imagepng($newImage, $newPath);
                break;
            case $outExt === 'gif':
                $success = imagegif($newImage, $newPath);
                break;
            case  $outExt === 'bmp':
                $success = imagebmp($newImage, $newPath);
                break;
            case  $outExt === 'webp':
                $success = imagewebp($newImage, $newPath);
        }

        if (!$success) {
            return false;
        }

        return $newPath;
    }
}