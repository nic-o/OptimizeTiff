#!/usr/bin/php -q
<?php

// For Platypus $argv contains:
// [0] - Absolute to the running script
// [1...n] - Absolute path to each dropped file
// var_dump($argv);
date_default_timezone_set('Asia/Jakarta');
define('NOW', microtime(true));
$init = parse_ini_file('./Optimize Tiff.ini', true);

if (!isset($init['directory']['root'])) {
  die('[Error] File init uncomplete.');
}
if (!isset($init['directory']['source']) && !is_array($init['directory']['source'])) {
  die('[Error] File init uncomplete.');
}

// KVC for check and options
foreach($init['check'] as $key => $value) {
  define("CHECK_" . strtoupper($key), $value);
}
foreach($init['option'] as $key => $value) {
  define("OPTION_" . strtoupper($key), $value);
}

////////////////////////////////////////////////////////////////

$dropped = array_slice($argv, 1);
$success = 0;
$error = array();
$warning = array();

if (!empty($dropped)) {
  foreach($dropped as $item) {
    $files = array();
    if(is_dir($item)) {
      $files = ListFiles($item, "{tif,tiff}");
      if(!empty($files)) {
        foreach($files as $image) {
          OptimizeTiff($image);
        }
      }
    } else {
      $finfo = finfo_open(FILEINFO_MIME_TYPE); 
      $mime = finfo_file($finfo, $item);
      if($mime == "image/tiff") {
        OptimizeTiff($item);
      }
    }
  }
} else {
  // We use the init:
  foreach($init['directory']['source'] as $source) {
    $source = $init['directory']['root'] . $source . DIRECTORY_SEPARATOR;
    if (file_exists($source)) {
      if (!is_writable($source)) {
          die('[Error] Server Phototheque is not permitted in access.');
      }
      $files = ListFiles($source, "{tif,tiff}");
      foreach($files as $image) {
        OptimizeTiff($image);
      }
    }
  }
}

// END

printf(PHP_EOL . "[Summary]" . PHP_EOL);
printf("➞ %d file(s) processed sucessfuly" . PHP_EOL, $success);
if(Count($warning) > 0) {
  printf("➞ %d warning(s) occured:" . PHP_EOL, count($warning));
  foreach($warning as $entry) {
    echo "  🌀 " . $entry . PHP_EOL;
  }
}
if(count($error) > 0) {
  printf("➞ %d error(s) occured:" . PHP_EOL, count($error));
  foreach($error as $entry) {
    echo "  ❌ " . $entry . PHP_EOL;
  }
}
printf("➞ Total time: %f seconde(s) @ %s" . PHP_EOL, microtime(true) - NOW, date('H:i:s'));

///////////////////////////////////////////////////////////////////////

function ListFiles($directory, $extension) {
  $paths = glob($directory . '*', GLOB_MARK | GLOB_ONLYDIR | GLOB_NOSORT);
  $files = glob($directory . "*." . $extension, GLOB_BRACE);
  foreach ($paths as $path) {
    $files = array_merge($files, ListFiles($path, $extension));
  }
  return $files;
}


function OptimizeTiff($image) {
  // Symbol Unicode: http://en.wikibooks.org/wiki/Unicode/List_of_useful_symbols
  global $success, $error, $warning;
  $start = microtime(true);
  $continue = true;
  
  exec('sips --getProperty all ' . escapeshellarg($image), $foo);
  $properties = array();
  foreach ($foo as $key => $value) {
    if($key == 0 ) { $properties['path'] = $value; }
    else {
      $tmp = explode(': ', $value);
      if(!empty($tmp[1])) {
        $properties[trim($tmp[0])] = $tmp[1];
      }
    }
  }
  if(CHECK_PIXELS == true && ((int)$properties["pixelWidth"] > OPTION_MAXPIXELS || (int)$properties["pixelHeight"] > OPTION_MAXPIXELS)) {
    printf("▲ «%s» has too many pixels." . PHP_EOL, basename($image));
    array_push($warning, "[" . basename($image) . "] Width or Height too large.");
    $continue = true;
  }
  if(CHECK_LZW == true && ($properties["formatOptions"] != "lzw")) {
    printf("▲ «%s» is not a LZW tiff image." . PHP_EOL, basename($image));
    array_push($warning, "[" . basename($image) . "] Not a LZW compressed.");
    $continue = true;
  }
  if(CHECK_RESOLUTION == true && ((int)$properties["dpiWidth"] < OPTION_MINRESOLUTION || (int)$properties["dpiHeight"] < OPTION_MINRESOLUTION)) {
    printf("✘ «%s» resolution is too low." . PHP_EOL, basename($image));
    array_push($error, "[" . basename($image) . "] Too low resolution.");
    $continue = false;
  }
  if(CHECK_BITS == true && ($properties["bitsPerSample"] > 8)) {
    printf("✘ «%s» is 16 Bits per channel image." . PHP_EOL, basename($image));
    array_push($error, "[" . basename($image) . "] 16 Bits/Channels images.");
    $continue = false;
  }
  if(CHECK_SPACE == true && (($properties["space"] != "CMYK" && $properties["space"] != "Gray"))) {
    printf("✘ «%s» has wrong color space." . PHP_EOL, basename($image));
    array_push($error, "[" . basename($image) . "] Bad color space.");
    $continue = false;
  }
  if(CHECK_HASPROFILE == true && !isset($properties["profile"])) {
    printf("▲ «%s» has no embedded ICC profile." . PHP_EOL, basename($image));
    array_push($warning, "[" . basename($image) . "] No ICC Profile.");
    $continue = true;
  }
  if(CHECK_PROFILE == true && isset($properties["profile"])) {
    if($properties["profile"] != OPTION_ICCPROFILECMYK && $properties["profile"] != OPTION_ICCPROFILEGRAY) {
      printf("▲ «%s» has wrong ICC profile." . PHP_EOL, basename($image));
      array_push($warning, "[" . basename($image) . "] Wrong ICC Profile.");
      $continue = true;
    }
  }
  if(OPTION_COMPRESSLZW == true && $continue == true) {
    $mfile = filemtime($image);
    exec("sips -s format tiff -s formatOptions lzw " . escapeshellarg($image));
    if(CHECK_KEEPTIMESTAMP == true) {
      touch($image, $mfile);
    }
    printf("✔ «%s» in %f secondes" . PHP_EOL, basename($image), microtime(true) - $start);
    $success++;
  }
}