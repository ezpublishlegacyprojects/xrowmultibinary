<?php
$Module = $Params['Module'];
$attribute = eZContentObjectAttribute::fetch( $Params['AttributeID'], $Params['Version'], array( 'language_code' => $Params['Language'] ) );

if ( ! $attribute )
{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}
$obj = $attribute->attribute( 'object' );

if ( ! $obj )
{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}

// If the object has status Archived (trash) we redirect to content/restore
// which can handle this status properly.
if ( $obj->attribute( 'status' ) == eZContentObject::STATUS_ARCHIVED )
{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}

if ( ! $obj->attribute( 'can_edit' ) )
{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}

$buffer_length = 1024 * 100;
// HTTP headers for no cache etc
header( 'Content-type: text/plain; charset=UTF-8' );
header( 'Expires: Mon, 26 Jul 1997 05:00:00 GMT' );
header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
header( 'Cache-Control: no-store, no-cache, must-revalidate' );
header( 'Cache-Control: post-check=0, pre-check=0', false );
header( 'Pragma: no-cache' );

// Settings
$targetDir = eZSys::instance()->storageDirectory() . DIRECTORY_SEPARATOR . 'plupload';
$cleanupTargetDir = false; // Remove old files
$maxFileAge = 60 * 60; // Temp file age in seconds

// Get parameters
$chunk = isset( $_REQUEST['chunk'] ) ? $_REQUEST['chunk'] : 0;
$chunks = isset( $_REQUEST['chunks'] ) ? $_REQUEST['chunks'] : 0;
$fileName = isset( $_REQUEST['name'] ) ? $_REQUEST['name'] : '';

$mime = eZMimeType::findByURL( $fileName );
$mime['suffix'] = eZFile::suffix( $fileName );
$mime2 = explode( '/', $mime['name'] );

$storeName = storeName( $fileName, $mime['suffix'], $mime2[0], $Params['Random'] );
// Create target dir
if ( !file_exists( dirname( $storeName ) ) )
{
    if ( !eZDir::mkdir( dirname( $storeName ), false, true ) )
    {
        die( '{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}' );
    }
}

// Look for the content type header
if ( isset( $_SERVER['HTTP_CONTENT_TYPE'] ) )
{
    $contentType = $_SERVER['HTTP_CONTENT_TYPE'];
}

if ( isset( $_SERVER['CONTENT_TYPE'] ) )
{
    $contentType = $_SERVER['CONTENT_TYPE'];
}

$logging = false;
$total = 0;
if( $logging && isset( $_REQUEST['chunk'] ) )
{
    eZLog::write(  "Name: ". $fileName . "CONTENT_TYPE: " .$contentType . ' Chunk ' . $_REQUEST['chunk'] . ' from ' . $_REQUEST['chunks'] , 'upload.log');
}
if ( strpos( $contentType, 'multipart' ) !== false )
{
    if ( isset( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) )
    {
        // Open temp file
        $out = fopen( $storeName, $chunk == 0 ? 'wb' : 'ab' );
        if ( $out )
        {
            // Read binary input stream and append it to temp file
            $in = fopen( $_FILES['file']['tmp_name'], 'rb' );
            
            if ( $in )
            {
                while ( $buff = fread( $in, $buffer_length ) )
                {
                    fwrite( $out, $buff );
                }
            }
            else
            {
                eZDebug::writeError( 'Failed to move uploaded file.', 'xrowmultibinary/upload' );
                die( '{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}' );
            }
            fclose( $out );
            $oldumask = umask( 0 );
            chmod( $storeName, octdec( eZINI::instance()->variable( 'FileSettings', 'StorageFilePermissions' ) ) );
            umask( $oldumask );
            unlink( $_FILES['file']['tmp_name'] );
        }
        else
        {
            eZDebug::writeError( 'Failed to move uploaded file.', 'xrowmultibinary/upload' );
            die( '{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}' );
        }
    }
    else
    {
        eZDebug::writeError( 'Failed to move uploaded file.', 'xrowmultibinary/upload' );
        die( '{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}' );
    }
}
else
{
    set_time_limit(0);
    $mem = $attribute->attribute( 'contentclass_attribute' )->DataInt1 * 2;// twice the max upload size
    ini_set('memory_limit', $mem.'M');
    // Open temp file
    $out = fopen( $storeName, $chunk == 0 ? 'wb' : 'ab' );
    if ( $out )
    {
        // Read binary input stream and append it to temp file
        $in = fopen( 'php://input', 'rb' );
        
        if ( $in )
        {
            while ( $buff = fread( $in, $buffer_length ) )
            {
                fwrite( $out, $buff );
                $total = filesize( $storeName );
                if( $logging )
                {
                    eZLog::write(  "File: " . $storeName . ' Bytes: ' . $total ,'upload.log');
                }
            }
        }
        else
        {
            eZDebug::writeError( 'Failed to open input stream.', 'xrowmultibinary/upload' );
            die( '{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}' );
        }
        fclose( $out );
        $oldumask = umask( 0 );
        chmod( $storeName, octdec( eZINI::instance()->variable( 'FileSettings', 'StorageFilePermissions' ) ) );
        umask( $oldumask );
    }
    else
    {
        eZDebug::writeError( 'Failed to open output stream.', 'xrowmultibinary/upload' );
        die( '{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}' );
    }
}
$targetchunk = $chunks -1;
if( isset( $_REQUEST['chunk'] ) and $chunk == $targetchunk )
{
    $contentObjectAttributeID = $attribute->attribute( 'id' );
    $version = $attribute->attribute( 'version' );

    $binary = eZBinaryFile2::create( $contentObjectAttributeID, $version );
    $binary->setAttribute( 'filename', basename( $storeName ) );
    $binary->setAttribute( 'original_filename', $fileName );
    $binary->setAttribute( 'mime_type', $mime['name'] );
    $binary->store();

    $fileHandler = eZClusterFileHandler::instance();
    $fileHandler->fileStore( $storeName, 'binaryfile', true, $mime );
}
elseif( !isset( $_REQUEST['chunk'] ) )
{
    $contentObjectAttributeID = $attribute->attribute( 'id' );
    $version = $attribute->attribute( 'version' );

    $binary = eZBinaryFile2::create( $contentObjectAttributeID, $version );
    $binary->setAttribute( 'filename', basename( $storeName ) );
    $binary->setAttribute( 'original_filename', $fileName );
    $binary->setAttribute( 'mime_type', $mime['name'] );
    $binary->store();
    
    $fileHandler = eZClusterFileHandler::instance();
    $fileHandler->fileStore( $storeName, 'binaryfile', true, $mime );
}

// Return JSON-RPC response
echo '{"jsonrpc" : "2.0", "result" : null, "id" : "'.basename( $storeName ).'"}';
eZExecution::cleanExit();
function storeName( $Filename = false, $suffix = false, $MimeCategory, $seed )
{
    $dir = eZSys::storageDirectory() . '/original/' . $MimeCategory;
    if ( !file_exists( $dir ) )
    {
        eZDir::mkdir( $dir, false, true );
    }
    $suffixString = false;
    if ( $suffix != false )
    {
        $suffixString = '.'.$suffix;
    }
    $dest_name = $dir . '/' . md5( basename( $Filename ) . $seed ) . $suffixString;
    
    return $dest_name;
}
?>
