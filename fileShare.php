<?php
session_start();

define('PUBLIC_PATH',         getenv('ENV_PUBLIC_PATH'));
define('LOCAL_PATH',          __DIR__ . '/');
define('USERNAME',            getenv('ENV_USERNAME'));
define('PASSWORD',            getenv('ENV_PASSWORD'));
define('AUTHENTICATED_TOKEN', getenv('ENV_TOKEN'));

define('APP_LOGGED_OUT',     0);
define('APP_AUTHENTICATING', 1);
define('APP_LOGGED_IN',      2);

#region Determine app state
if (!isset($_SESSION['token']) && count($_POST) > 0) 
{
	$formState = APP_AUTHENTICATING;
}
elseif (isset($_SESSION['token']) && $_SESSION['token'] === AUTHENTICATED_TOKEN)
{
	$formState = APP_LOGGED_IN;
}
else
{
	$formState = APP_LOGGED_OUT;
}
#endregion

#region App routing
if ($formState === APP_AUTHENTICATING)
{
	if (!validateCredentials())
	{
		$response = uiLoginFailed();
	}
	else
	{
		$formState          = APP_LOGGED_IN;
		$_SESSION['token']  = AUTHENTICATED_TOKEN;
		$response           = uiAppForm();
	}
}
elseif ($formState === APP_LOGGED_IN)
{
	$response = uiAppForm();
}
else
{
	$response = uiLoginForm();
}
#endregion

#region Send response
header($response['header']);
echo $response['body'];
die;
#endregion

//////////////////////////////////////////////////////////////////////
#region Authentication
function uiLoginForm()
{
	$head = head();
	return ['header' => 'HTTP/1.0 200 OK', 'body' => <<<HTML
		<html>
		{$head}
		<body>
		<h1>You are not logged in</h1>
		<p>Please enter your credentials below.</p>
		<form method="POST" enctype="multipart/form-data">
		<p><input type="text" name="username" placeholder="Username"></p>
		<p><input type="password" name="password" placeholder="Password"></p>
		<input type="submit" value="Log in">
		</form>
		</body>
		</html>
	HTML];
}

function uiLoginFailed()
{
	return ['header' => 'HTTP/1.0 403 Forbidden', 'body' => <<<HTML
		<h1>Invalid username/password</h1>
		<a href="{$_SERVER['REQUEST_URI']}">Try again</a>
	HTML];
}

function validateCredentials()
{
	return strtolower($_POST['username']) === strtolower(USERNAME)
		&& $_POST['password'] === PASSWORD;
}
#endregion

#region Main app
function uiAppForm()
{
	$latest = '';
	if (isset($_POST['action']) && $_POST['action'] === 'actionUpload')
	{
		if (!validateUpload())
		{
			return ['header' => 'HTTP/1.0 400 Bad Request', 'body' => <<<HTML
				<h1>Share file name or files missing</h1>
				<a href="{$_SERVER['REQUEST_URI']}">Try again</a>
			HTML];
		}

		$desiredZipName   = sanitize($_POST['zipName'] . '-' . getRandomSuffix(6));
		$tempZipDir       = LOCAL_PATH . $desiredZipName;
		$desiredFileNames = prepareFiles($tempZipDir);
		$latestZipUrl     = zipDirectory($tempZipDir, $desiredFileNames, $desiredZipName);

		$body             = rawurlencode($latestZipUrl);
		$latest           = <<<HTML
			<h1>Your new file</h1>
			<p><a href="{$latestZipUrl}">{$latestZipUrl}</a></p>
			<p style="font-size: 150%;"><a href="mailto:?body={$body}">?? Email this link</a></p>
		HTML;
	}
	elseif (isset($_POST['action']) && $_POST['action'] === 'actionDelete')
	{
		$file = LOCAL_PATH . $_POST['file'];
		unlink($file);
	}
	else
	{
		// anything while not uploading or deleting
	}

	$uploadForm = <<<HTML
		<h1>Upload new files</h1>
		<form method="POST" enctype="multipart/form-data">
		<input type="hidden" name="action" value="actionUpload">
		<p><input type="text" name="zipName" maxlength="20" size="20" placeholder="enter zip file name here"></p>
		<p><input type="file" name="uploads[]" multiple></p>
		<p><input type="submit" value="Upload" onclick="submitClick(this)"></p>
		</form>
	HTML;

	$fileListing = '<h1>List of available files</h1>';
	$existingZipFiles = getZipFiles();
	foreach ($existingZipFiles as $key=>$file) 
	{
		$existingFileUrl = PUBLIC_PATH . $file;
		$info = date("F j Y H:i:s", filemtime($file)) . ', ' . number_format(filesize($file) / 1048576, 1) . ' MB';
		$deleteForm = <<<HTML
			<form method="POST">
			<input type="hidden" name="action" value="actionDelete">
			<input type="hidden" name="file" value="{$file}">
			<input type="submit" value="Delete File">
			</form>
		HTML;
		$fileListing .= <<<HTML
			<p><a href="{$existingFileUrl}">{$existingFileUrl}</a><br>
			{$info}</p>
			{$deleteForm}
			<p style="margin-bottom: 2em;"></p>
		HTML;
	}

	$head = head();
	return ['header' => 'HTTP/1.0 200 OK', 'body' => <<<HTML
		<html>
		{$head}
		<body>
		{$uploadForm}
		{$latest}
		{$fileListing}
		</body>
		</html>
	HTML];
}

function validateUpload()
{
	return $_POST['zipName'] !== '' 
		&& $_FILES['uploads']['tmp_name'][0] !== '';
}

function getRandomSuffix($length)
{
	//(new DateTimeImmutable())->format('ymdHis') // YYMMDDHHMMSS
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$suffix = '';
	for ($i = 0; $i < $length; $i++)
	{
		$suffix .= $chars[rand(0, strlen($chars) - 1)];
	}
	return $suffix;
}

function sanitize($fileName)
{
	return preg_replace('/[^a-zA-Z0-9-_\.]*/', '', $fileName);
}

function prepareFiles($tempZipDir)
{
	// zip directory
	if (!mkdir($tempZipDir))
		throw new Exception("Failed to create temporary directory {$tempZipDir}");

	$desiredFileNames = [];
	for ($i = 0; $i < count($_FILES['uploads']['name']); $i++)
	{
		$name = sanitize($_FILES['uploads']['name'][$i]);
		$file = $_FILES['uploads']['tmp_name'][$i];
		move_uploaded_file($file, $tempZipDir . '/' . $name);
		$desiredFileNames[] = $name;
	}
	return $desiredFileNames;
}

function zipDirectory($tempDir, $desiredFileNames, $desiredZipName)
{
	// create archive
	$zip = new ZipArchive();
	$zip->open(LOCAL_PATH . $desiredZipName . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

	// add files
	foreach ($desiredFileNames as $file)
	{
		$tempFile = $tempDir . '/' . $file;
		$zip->addFile($tempFile, $file);
	}

	// finish the archive
	$zip->close();

	// clean up
	foreach ($desiredFileNames as $file)
	{
		$tempFile = $tempDir . '/' . $file;
		unlink($tempFile);
	}
	rmdir($tempDir);

	return PUBLIC_PATH . $desiredZipName . '.zip';
}

function getZipFiles()
{
	return array_filter(scandir(LOCAL_PATH), function($dirEntry) {
			return substr($dirEntry, -4) === '.zip';});
}
#endregion

function head()
{
	return <<<HEAD
		<head>
		<title>File Share</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<style>
			* { font-family: Helvetica, Arial; }
			body { margin: 0; padding: 10px; border-top: 3px solid #23408e; background-color: #f1f1f1; }
			a { color: #23408e; }
			.clicked { background-color: #23408e; }
		</style>
		<script>
			function submitClick(btn)
			{
				btn.value = 'Uploading...';
				btn.form.submit();
				btn.disabled = true;
			}
		</script>
		</head>
	HEAD;
}
