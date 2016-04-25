<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'shared/connect.php';

header('Content-Type: application/json');

if (!isset($_POST) || !isset($_POST['action'])) {
	die(json_encode(['success' => false, 'error' => 'missing action']));
}

function checkoutSvn($url, $user, $password, $revision) {
	global $config;
	$dir = mt_rand(100000, mt_getrandmax());
	svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_USERNAME,             $user);
	svn_auth_set_parameter(SVN_AUTH_PARAM_DEFAULT_PASSWORD,             $password);
	svn_auth_set_parameter(PHP_SVN_AUTH_PARAM_IGNORE_SSL_VERIFY_ERRORS, true); // <--- Important for certificate issues!
	svn_auth_set_parameter(SVN_AUTH_PARAM_NON_INTERACTIVE,              true);
	svn_auth_set_parameter(SVN_AUTH_PARAM_NO_AUTH_CACHE,                true);
	$sucess = true;
	try {
		if ($revision) {
			$success = svn_checkout($url, __DIR__.'/files/checkouts/'.$dir, $revision);
		} else {
			$success = svn_checkout($url, __DIR__.'/files/checkouts/'.$dir);
		}
	} catch (Exception $e) {
		echo(json_encode(['success' => false, 'error' => $e->getMessage()]));
	}
	echo(json_encode(['success' => $success, 'dir' => $config->baseUrl.'/files/checkouts/'.$dir, 'ID' => $dir]));
}

function saveLimits($taskId, $limits) {
	global $db;
	if (!$limits || !count($limits)) {
		return;
	}
	$stmt = $db->prepare('insert ignore into tm_tasks_limits (idTask, sLangProg, iMaxTime, iMaxMemory) values (:taskId, :lang, :maxTime, :maxMemory) on duplicate key update iMaxTime = values(iMaxTime), iMaxMemory = values(iMaxMemory);');
	foreach ($limits as $lang => $limits) {
		$maxTime = isset($limits['time']) ? $limits['time'] : 0;
		$maxMemory = isset($limits['memory']) ? $limits['memory'] : 0;
		$stmt->execute(['taskId' => $taskId, 'lang' => $lang, 'maxTime' => $maxTime, 'maxMemory' => $maxMemory]);
	}
}

function saveTask($metadata) {
	global $db;
	$authors = (isset($metadata['authors']) && count($metadata['authors'])) ? join(',', $metadata['authors']) : '';
	$sSupportedLangProg = (isset($metadata['supportedLanguages']) && count($metadata['supportedLanguages'])) ? join(',', $metadata['supportedLanguages']) : '*';
	$bUserTests = isset($metadata['hasUserTests']) ? $metadata['hasUserTests'] : 0;
	$stmt = $db->prepare('insert into tm_tasks (sTextId, sSupportedLangProg, sAuthor, bUserTests) values (:id, :langprog, :authors, :bUserTests) on duplicate key update sSupportedLangProg = values(sSupportedLangProg), sAuthor = values(sAuthor), bUserTests = values(bUserTests);');
	$stmt->execute(['id' => $metadata['id'], 'langprog' => $sSupportedLangProg, 'authors' => $authors, 'bUserTests' => $bUserTests]);
	$stmt = $db->prepare('select id from tm_tasks where sTextId = :id;');
	$stmt->execute(['id' => $metadata['id']]);
	$taskId = $stmt->fetchColumn();
	if (!$taskId) {
		die(json_encode(['success' => false, 'impossible to find id for '.$metadata['id']]));
	}
	return $taskId;
}

function saveStrings($taskId, $resources, $metadata) {
	global $db;
	$statement = null;
	$solution = null;
	$css = null;
	foreach ($resources['task'] as $i => $resource) {
		if ($resource['type'] == 'html') {
			$statement = $resource['content'];
		} elseif ($resource['type'] == 'css' && isset($resource['content'])) {
			$css = $resource['content'];
		}
	}
	foreach ($resources['solution'] as $i => $resource) {
		if ($resource['type'] == 'html') {
			$solution = $resource['content'];
			break;
		}
	}
	$stmt = $db->prepare('insert into tm_tasks_strings (idTask, sLanguage, sTitle, sStatement, sSolution, sCss) values (:idTask, :sLanguage, :sTitle, :sStatement, :sSolution, :sCss) on duplicate key update sTitle = values(sTitle), sStatement = values(sStatement), sSolution = values(sSolution), sCss = values(sCss);');
	$stmt->execute(['idTask' => $taskId, 'sLanguage' => $metadata['language'], 'sTitle' => $metadata['title'], 'sStatement' => $statement, 'sSolution' => $solution, 'sCss' => $css]);
}

function saveHints($taskId, $hintsResources, $metadata) {
	global $db;
	foreach ($hintsResources as $i => $resources) {
		foreach ($resources as $j => $resource) {
			if ($resource['type'] == 'html') {
				$hint = $resource['content'];
				break;
			}
		}
		if ($hint) {
			$stmt = $db->prepare('insert ignore into tm_hints (idTask, iRank) values (:idTask, :iRank);');
			$stmt->execute(['idTask' => $taskId, 'iRank' => $i+1]);
			$stmt = $db->prepare('select id from tm_hints where idTask = :idTask and iRank = :iRank;');
			$stmt->execute(['idTask' => $taskId, 'iRank' => $i+1]);
			$idHint = $stmt->fetchColumn();
			if (!$idHint) {
				die(json_encode(['success' => false, 'error' => 'impossible to find hint '.($i+1).' for task '.$taskId]));
			}
			$stmt = $db->prepare('insert ignore into tm_hints_strings (idHint, sLanguage, sContent) values (:idHint, :sLanguage, :sContent) on duplicate key update sContent = values(sContent);');
			$stmt->execute(['idHint' => $idHint, 'sLanguage' => $metadata['language'], 'sContent' => $hint]);
		}
	}
}

function saveAnswer($taskId, $answer) {
	global $db;
	$deleteQuery = 'delete from tm_source_codes where idTask = :idTask and sName = :sName and sType = :sType;';
	$insertQuery = 'insert into tm_source_codes (idTask, sDate, sParams, sName, sSource, bEditable, sType) values (:idTask, NOW(), :sParams, :sName, :sSource, 0, :sType);';
	$resourceName = $answer['name'];
	if (!$resourceName) {
		die(json_encode(['sucess' => false, 'error' => 'missing name field in answer resource']));
	}
	$stmt = $db->prepare($deleteQuery);
	$stmt->execute(['idTask' => $taskId, 'sName' => $resourceName, 'sType' => 'Task']);
	$stmt = $db->prepare($insertQuery);
	if (isset($answer['answerVersions']) && count($answer['answerVersions'])) {
		foreach ($answer['answerVersions'] as $i => $answerVersion) {
			$sParams = json_encode($answerVersion['params']);
			$stmt->execute(['idTask' => $taskId, 'sName' => $resourceName, 'sType' => 'Task', 'sParams' => $sParams, 'sSource' => $answerVersion['answerContent']]);
		}
	} elseif ($answer['answerContent']) {
		$sParams = json_encode($answer['params']);
		$stmt->execute(['idTask' => $taskId, 'sName' => $resourceName, 'sType' => 'Task', 'sParams' => $sParams, 'sSource' => $answer['answerContent']]);
	}
}

function saveSourceCodes($taskId, $resources) {
	foreach ($resources['task'] as $i => $resource) {
		if ($resource['type'] == 'answer') {
			saveAnswer($taskId, $resource);
		}
	}
	foreach ($resources['solution'] as $i => $resource) {
		if ($resource['type'] == 'answer') {
			saveAnswer($taskId, $resource);
		}
	}
}

function saveSamples($taskId, $resources) {
	global $db;
	$insertQuery = 'insert into tm_tasks_tests (idTask, sGroupType, sName, sInput, sOutput) values (:idTask, :sGroupType, :sName, :sInput, :sOutput);';
	$deleteQuery = 'delete from tm_tasks_tests where idTask = :idTask and sGroupType = :sGroupType and sName = :sName;';
	foreach ($resources['task'] as $i => $resource) {
		if ($resource['type'] == 'sample' && isset($resource['name']) && $resource['name']) {
			$stmt = $db->prepare($deleteQuery);
			$stmt->execute(['idTask' => $taskId, 'sGroupType' => 'Example', 'sName' => $resource['name']]);
			$stmt = $db->prepare($insertQuery);
			$stmt->execute(['idTask' => $taskId, 'sGroupType' => 'Example', 'sName' => $resource['name'], 'sInput' => $resource['inContent'], 'sOutput' => $resource['outContent']]);
		}
	}
	if (!isset($resources['grader'])) {
		return;
	}
	foreach ($resources['grader'] as $i => $resource) {
		if ($resource['type'] == 'sample' && isset($resource['name']) && $resource['name']) {
			$stmt = $db->prepare($deleteQuery);
			$stmt->execute(['idTask' => $taskId, 'sGroupType' => 'Evaluation', 'sName' => $resource['name']]);
			$stmt = $db->prepare($insertQuery);
			$stmt->execute(['idTask' => $taskId, 'sGroupType' => 'Evaluation', 'sName' => $resource['name'], 'sInput' => $resource['inContent'], 'sOutput' => $resource['outContent']]);
		}
	}
}

function saveResources($metadata, $resources) {
	if (!isset($metadata['id']) || !isset($metadata['language'])) {
		die(json_encode(['success' => false, 'error' => 'missing id or language in metadata']));
	}
	$textId = $metadata['id'];
	// save task to get ID
	$taskId = saveTask($metadata);
	// limits
	saveLimits($taskId, $metadata['limits']);
	// strings
	saveStrings($taskId, $resources, $metadata);
	// hints
	if (isset($resources['hints']) && count($resources['hints'])) {
		saveHints($taskId, $resources['hints'], $metadata);
	}
	// source code
	saveSourceCodes($taskId, $resources);
	saveSamples($taskId, $resources);
	echo(json_encode(['success' => true]));
}

// why is there no such thing in the php library?
function deleteRecDirectory($dir) {
	if (!$dir || $dir == '/') return;
	$it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
	$files = new RecursiveIteratorIterator($it,
	             RecursiveIteratorIterator::CHILD_FIRST);
	foreach($files as $file) {
	    if ($file->isDir()){
	        rmdir($file->getRealPath());
	    } else {
	        unlink($file->getRealPath());
	    }
	}
	rmdir($dir);	
}

function deleteDirectory($ID) {
	$ID = intval($ID);
	if ($ID < 1) {
		die(json_encode(['success' => false, 'error' => 'invalid ID format (must be number)']));
	}
	deleteRecDirectory(__DIR__.'/files/checkouts/'.$ID);
	echo(json_encode(['success' => true]));
}

if ($_POST['action'] == 'checkoutSvn') {
	if (!isset($_POST['svnUrl'])) {
		die(json_encode(['success' => false, 'error' => 'missing svnUrl']));
	}
	$user = $_POST['svnUser'] ? $_POST['svnUser'] : $config->defaultSvnUser;
	$password = $_POST['svnPassword'] ? $_POST['svnPassword'] : $config->defaultSvnPassword;
	checkoutSvn($_POST['svnUrl'], $user, $password, $_POST['svnRev']);
} elseif ($_POST['action'] == 'saveResources') {
	if (!isset($_POST['metadata']) || !isset($_POST['resources'])) {
		die(json_encode(['success' => false, 'error' => 'missing metada or resources']));
	}
	saveResources($_POST['metadata'], $_POST['resources']);
} elseif ($_POST['action'] == 'deletedirectory') {
	if (!isset($_POST['ID'])) {
		die(json_encode(['success' => false, 'error' => 'missing directory']));
	}
	deleteDirectory($_POST['ID']);
} else {
	echo(json_encode(['success' => false, 'error' => 'unknown action '.$_POST['action']]));	
}