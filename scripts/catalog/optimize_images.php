<?php
// Resize WP product image originals + clean WP-generated thumbnail siblings
// Usage: php optimize_images.php <upload_dir>
//   1. Backs up each original under ~/papelito-img-backup-<ts>/
//   2. Resizes original to max 1500px width using Imagick
//   3. Removes WP-generated <name>-WxH.png siblings (regenerated later by wp-cli)

$upload_dir = $argv[1] ?? '';
if ( ! $upload_dir || ! is_dir( $upload_dir ) ) {
    fwrite( STDERR, "Usage: php optimize_images.php <upload_dir>\n" );
    exit( 1 );
}

if ( ! extension_loaded( 'imagick' ) ) {
    fwrite( STDERR, "Imagick extension not loaded.\n" );
    exit( 1 );
}

$max_w  = 1500;
$ts     = date( 'Ymd-His' );
$backup = getenv( 'HOME' ) . "/papelito-img-backup-$ts";
if ( ! is_dir( $backup ) ) mkdir( $backup, 0755, true );

$entries = scandir( $upload_dir );
$originals = [];
foreach ( $entries as $f ) {
    if ( $f === '.' || $f === '..' ) continue;
    if ( ! preg_match( '/\.png$/i', $f ) ) continue;
    if ( preg_match( '/-\d+x\d+\.png$/i', $f ) ) continue; // WP thumb
    if ( preg_match( '/-scaled\.png$/i', $f ) )    continue; // WP "scaled"
    $originals[] = $f;
}

echo "Originals to process: " . count( $originals ) . "\n";
$saved_total = 0;

foreach ( $originals as $orig_name ) {
    $orig_path = "$upload_dir/$orig_name";
    $base = preg_replace( '/\.png$/i', '', $orig_name );

    // Backup original (move so disk recovers)
    $bk_path = "$backup/$orig_name";
    if ( ! copy( $orig_path, $bk_path ) ) {
        fwrite( STDERR, "  ! cannot backup $orig_name — skipping\n" );
        continue;
    }
    $orig_size = filesize( $orig_path );

    try {
        $img = new Imagick( $orig_path );
        $img->stripImage();
        $w = $img->getImageWidth();
        if ( $w > $max_w ) {
            $img->resizeImage( $max_w, 0, Imagick::FILTER_LANCZOS, 1 );
        }
        $img->setImageFormat( 'png' );
        $img->setOption( 'png:compression-level', '9' );
        $img->writeImage( $orig_path );
        $img->clear();
        $img->destroy();
    } catch ( Exception $e ) {
        fwrite( STDERR, "  ! imagick failed on $orig_name: " . $e->getMessage() . "\n" );
        // restore from backup
        copy( $bk_path, $orig_path );
        continue;
    }

    // delete WP-generated siblings <base>-WxH.png and -scaled.png
    $deleted = 0;
    foreach ( glob( "$upload_dir/$base-*.png" ) as $sibling ) {
        if ( preg_match( '/-(\d+x\d+|scaled)\.png$/i', basename( $sibling ) ) ) {
            unlink( $sibling );
            $deleted++;
        }
    }

    $new_size = filesize( $orig_path );
    $saved = $orig_size - $new_size;
    $saved_total += $saved;
    printf( "  %-50s %s → %s  (-%s, %d siblings cleaned)\n",
        substr( $orig_name, 0, 50 ),
        format_bytes( $orig_size ),
        format_bytes( $new_size ),
        format_bytes( $saved ),
        $deleted
    );
}

echo "\nTotal saved: " . format_bytes( $saved_total ) . "\n";
echo "Backup at: $backup\n";

function format_bytes( $b ) {
    if ( $b < 1024 )            return $b . 'B';
    if ( $b < 1024*1024 )       return round( $b/1024, 1 ) . 'K';
    return round( $b / 1024 / 1024, 2 ) . 'M';
}
