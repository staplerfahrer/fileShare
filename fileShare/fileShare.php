<?php
$timeout = 10*365*24*3600;

if (file_exists(__DIR__ . '/secrets.php'))
{
	require __DIR__ . '/secrets.php';
}

define('PUBLIC_PATH',         getenv('ENV_PUBLIC_PATH'));
define('LOCAL_PATH',          __DIR__ . '/');
define('USERNAME',            getenv('ENV_USERNAME'));
define('PASSWORD',            getenv('ENV_PASSWORD'));
define('AUTHENTICATED_TOKEN', getenv('ENV_TOKEN'));

define('APP_LOGGED_OUT',     0);
define('APP_AUTHENTICATING', 1);
define('APP_LOGGED_IN',      2);

#region Determine app state
if (!isset($_COOKIE['token']) && count($_POST) > 0)
{
	$formState = APP_AUTHENTICATING;
}
elseif (isset($_COOKIE['token']) && $_COOKIE['token'] === AUTHENTICATED_TOKEN)
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
		setcookie('token', AUTHENTICATED_TOKEN, time()+$timeout, '/fileShare', '', false, true);
		$formState = APP_LOGGED_IN;
		$response  = uiAppForm();
	}
}
elseif ($formState === APP_LOGGED_IN)
{
	$response = uiAppForm();
}
else
{
	setcookie('token', '', -1, '/fileShare', '', false, true);
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
		$zipPassword      = $_POST['zipPassword'];
		$tempZipDir       = LOCAL_PATH . $desiredZipName;
		$desiredFileNames = prepareFiles($tempZipDir);
		$latestZipUrl     = zipDirectory($tempZipDir, $desiredFileNames, $desiredZipName, $zipPassword);

		$body             = rawurlencode("{$latestZipUrl}\n\nFor best results, use a computer to open this file.");
		$latest           = <<<HTML
			<h1>Your new file</h1>
			<p><a href="{$latestZipUrl}">{$latestZipUrl}</a></p>
			<p style="font-size: 150%;"><a href="mailto:?body={$body}">📨 Email this link</a></p>
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
		<form id="uploadForm">
		<input type="hidden" name="action" value="actionUpload">
		<p>Zip file name: <input type="text" name="zipName" maxlength="20" size="20" placeholder="enter zip file name here"></p>
		<p>Zip password: <input type="text" name="zipPassword" maxlength="20" size="20" placeholder="enter zip password here"> (optional)</p>
		<p><input type="file" id="uploadFiles" name="uploads[]" multiple></p>
		<p><input type="submit" value="Upload" disabled></p>
		</form>
		<progress id="progressBar" value="0" max="100" style="width:100%; display:none;"></progress>
		<p id="uploadStatus"></p>
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
	$usedNames        = [];
	for ($i = 0; $i < count($_FILES['uploads']['name']); $i++)
	{
		$name = uniqueName(sanitize($_FILES['uploads']['name'][$i]), $usedNames);
		$file = $_FILES['uploads']['tmp_name'][$i];
		move_uploaded_file($file, $tempZipDir . '/' . $name);
		$desiredFileNames[] = $name;
	}
	return $desiredFileNames;
}

function uniqueName($name, &$usedNames)
{
	// append -2, -3, ... before the extension until the name is unused (case-insensitive, for Windows)
	$base = pathinfo($name, PATHINFO_FILENAME);
	$ext  = pathinfo($name, PATHINFO_EXTENSION);
	$ext  = $ext === '' ? '' : '.' . $ext;

	$unique = $name;
	for ($n = 2; isset($usedNames[strtolower($unique)]); $n++)
	{
		$unique = "{$base}-{$n}{$ext}";
	}
	$usedNames[strtolower($unique)] = true;
	return $unique;
}

function zipDirectory($tempDir, $desiredFileNames, $desiredZipName, $password)
{
	// create archive
	$zip = new ZipArchive();
	$zip->open(LOCAL_PATH . $desiredZipName . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

	// add files
	foreach ($desiredFileNames as $file)
	{
		$tempFile = $tempDir . '/' . $file;
		$zip->addFile($tempFile, $file);
		if (strlen($password)) {
			$zip->setEncryptionName($file, ZipArchive::EM_AES_256, $password);
		}
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
	$files = array_filter(scandir(LOCAL_PATH), function($dirEntry) {
			return substr($dirEntry, -4) === '.zip';});

	$fileDict = [];
	foreach($files as $file)
	{
		$fileDate = (string)filemtime($file);
		$fileDict[$fileDate] = $file;
	}

	krsort($fileDict);

	$files = array();
	foreach($fileDict as $key=>$value)
	{
		$files[] = $value;
	}
	return $files;
}
#endregion

function head()
{
	return <<<HEAD
		<head>
		<title>Nets & More File Share</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<style>
			* { font-family: Helvetica, Arial; box-sizing: border-box; }
			body {
				margin: 0 auto;
				padding: 10px;
				border-top: 3px solid #23408e;
				background-color: #f1f1f1;
				font-size: 16px;
				max-width: 480px;
			}
			a { color: #23408e; }
			.clicked { background-color: #23408e; }
			input[type=text], input[type=password], input[type=file] {
				display: block;
				width: 100%;
				min-height: 44px;
				padding: 8px;
				font-size: 16px;
			}
			input[type=submit] {
				display: block;
				width: 100%;
				min-height: 44px;
				font-size: 16px;
				background-color: #23408e;
				color: #fff;
				border: none;
				border-radius: 4px;
			}
			input[type=submit]:disabled { background-color: #a3aecb; }
			progress { min-height: 20px; }
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const form = document.getElementById('uploadForm');
				const fileInput = document.getElementById('uploadFiles');
				const progressBar = document.getElementById('progressBar');
				const statusText = document.getElementById('uploadStatus');
				const body = document.querySelector('body');
				const submitBtn = document.querySelector('input[type=submit]');

				fileInput.addEventListener('change', function () {
					submitBtn.disabled = fileInput.files.length === 0;
				});

				form.addEventListener('submit', function (e) {
					e.preventDefault();

					submitBtn.value = 'Uploading, please wait...';
					submitBtn.disabled = true;

					const formData = new FormData(form);
					const xhr = new XMLHttpRequest();

					xhr.open('POST', window.location.href, true);

					xhr.upload.addEventListener('progress', function (e) {
						if (e.lengthComputable) {
							const percentComplete = Math.round((e.loaded / e.total) * 100);
							progressBar.style.display = 'block';
							progressBar.value = percentComplete;
							statusText.textContent = `Upload progress: \${percentComplete}%`;
						}
					});

					xhr.onload = function () {
						if (xhr.status === 200) {
							statusText.textContent = 'Upload complete. Reloading...';
							body.innerHTML = xhr.response;
						} else {
							body.innerHTML = xhr.response;
						}
					};

					xhr.onerror = function () {
						statusText.textContent = 'An error occurred during the upload.';
					};

					xhr.send(formData);

				});
			});

		</script>
		</head>
	HEAD;
}