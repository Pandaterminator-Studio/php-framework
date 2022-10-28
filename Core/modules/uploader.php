<?php

namespace Core\modules;
class uploader
{

    private $resizer;
    private $security;
    private bool $sameFileName;
    private bool $sameName;

    function __construct($resizer, $security)
    {
        $this->resizer = $resizer;
        $this->security = $security;
    }

    private $destinationPath;
    private $errorMessage;
    private $extensions;
    private $allowAll;
    private $maxSize;
    private $uploadName;
    private $seqnence;
    private $imageSeq;
    public string $name = 'Uploader';
    public bool $useTable = true;

    public function setDir($path): void
    {
        $this->destinationPath = $path;
        $this->allowAll = false;
    }

    public function allowAllFormats(): void
    {
        $this->allowAll = true;
    }

    public function setMaxSize($sizeMB): void
    {
        $this->maxSize = $sizeMB * (1024 * 1024);
    }

    public function setExtensions($options): void
    {
        $this->extensions = $options;
    }

    public function setSameFileName(): void
    {
        $this->sameFileName = true;
        $this->sameName = true;
    }

    public function getExtension($string): string
    {
        $ext = "";
        try {
            $parts = explode(".", $string);
            $ext = strtolower($parts[count($parts) - 1]);
        } catch (Exception $c) {
            $ext = "";
        }
        return $ext;
    }

    function setMessage($message): void
    {
        $this->errorMessage = $message;
    }

    function getMessage()
    {
        return $this->errorMessage;
    }

    function getUploadName()
    {
        return $this->uploadName;
    }

    function setSequence($seq): void
    {
        $this->imageSeq = $seq;
    }

    function getRandom(): string
    {
        return strtotime(date('Y-m-d H:i:s')) . rand(1111, 9999) . rand(11, 99) . rand(111, 999);
    }

    function sameName($true): void
    {
        $this->sameName = $true;
    }

    function uploadFile($fileBrowse): bool
    {
        $result = false;
        $size = $_FILES[$fileBrowse]["size"];
        $name = $_FILES[$fileBrowse]["name"];
        $ext = $this->getExtension($name);
        if (!is_dir($this->destinationPath)) {
            $this->setMessage("Destination folder is not a directory ");
        } else if (!is_writable($this->destinationPath)) {
            $this->setMessage("Destination is not writable !");
        } else if (empty($name)) {
            $this->setMessage("File not selected ");
        } else if ($size > $this->maxSize) {
            $this->setMessage("Too large file !");
        } else if ($this->allowAll || (in_array($ext, $this->extensions))) {

            if (!$this->sameName("")) {
                $seq = $this->imageSeq;
                $this->uploadName = $seq . "-" . substr(md5(rand(1111, 9999)), 0, 8) . $this->getRandom() . rand(1111, 1000) . rand(99, 9999) . "." . $ext;
            } else {
                $this->uploadName = $name;
            }

            if (move_uploaded_file($_FILES[$fileBrowse]["tmp_name"], $this->destinationPath . $this->uploadName)) {
                $result = true;
            } else {
                $this->setMessage("Upload failed , try later !");
            }
        } else {
            $this->setMessage("Invalid file format !");
        }
        return $result;
    }

    function deleteUploaded(): void
    {
        unlink($this->destinationPath . $this->uploadName);
    }

    public function ImageUpload($input, $output, $resize, $w, $h)
    {
        if ($resize) {
            $inputx = $this->resizer->Resize($input, $output, $w, $h);
            $this->setDir($output);
            $this->setExtensions(image_allowed_files);  //allowed extensions list//
            $this->setMaxSize(n_image_max_file_size);                          //set max file size to be allowed in MB//
            if ($this->uploadFile($inputx)) {   //txtFile is the filebrowse element name //
                return $this->getUploadName(); //return new filename
            } else {//upload failed
                return $this->getMessage(); //get upload error message
            }
        } else {
            $this->setDir($output);
            $this->setExtensions(image_allowed_files);  //allowed extensions list//
            $this->setMaxSize(n_image_max_file_size);                          //set max file size to be allowed in MB//
            if ($this->uploadFile($input)) {   //txtFile is the filebrowse element name //
                return $this->getUploadName(); //return new filename
            } else {//upload failed
                return $this->getMessage(); //get upload error message
            }
        }
    }


}