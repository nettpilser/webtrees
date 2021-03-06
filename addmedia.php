<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2017 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace Fisharebest\Webtrees;

use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\Functions\Functions;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Query\QueryMedia;

/** @global Tree $WT_TREE */
global $WT_TREE;

require 'includes/session.php';

$NO_UPDATE_CHAN  = $WT_TREE->getPreference('NO_UPDATE_CHAN');
$MEDIA_DIRECTORY = $WT_TREE->getPreference('MEDIA_DIRECTORY');

$pid         = Filter::get('pid', WT_REGEX_XREF, Filter::post('pid', WT_REGEX_XREF)); // edit this media object
$linktoid    = Filter::get('linktoid', WT_REGEX_XREF, Filter::post('linktoid', WT_REGEX_XREF)); // create a new media object, linked to this record
$action      = Filter::post('action');
$filename    = Filter::get('filename', null, Filter::post('filename'));
$text        = Filter::postArray('text');
$tag         = Filter::postArray('tag', WT_REGEX_TAG);
$islink      = Filter::postArray('islink');
$glevels     = Filter::postArray('glevels', '[0-9]');
$folder      = Filter::post('folder');
$update_CHAN = !Filter::postBool('preserve_last_changed');

$controller = new PageController;
$controller
	->restrictAccess(Auth::isMember($WT_TREE));

$disp  = true;
$media = Media::getInstance($pid, $WT_TREE);
if ($media) {
	$disp = $media->canShow();
}
if ($action == 'create') {
	if ($linktoid) {
		$disp = GedcomRecord::getInstance($linktoid, $WT_TREE)->canShow();
	}
}

if (!Auth::isEditor($WT_TREE) || !$disp) {
	$controller
		->pageHeader()
		->addInlineJavascript('closePopupAndReloadParent();');

	return;
}

// There is a lot of common code in the admin_media_upload.php script

switch ($action) {
case 'create': // Save the information from the “showcreateform” action
	$controller->setPageTitle(I18N::translate('Create a media object'));

	// Validate the media folder
	$folderName = str_replace('\\', '/', $folder);
	$folderName = trim($folderName, '/');
	if ($folderName == '.') {
		$folderName = '';
	}
	if ($folderName) {
		$folderName .= '/';
		// Not allowed to use “../”
		if (strpos('/' . $folderName, '/../') !== false) {
			FlashMessages::addMessage('Folder names are not allowed to include “../”');
			break;
		}
	}

	// Make sure the media folder exists
	if (!is_dir(WT_DATA_DIR . $MEDIA_DIRECTORY)) {
		if (File::mkdir(WT_DATA_DIR . $MEDIA_DIRECTORY)) {
			FlashMessages::addMessage(I18N::translate('The folder %s has been created.', Html::filename(WT_DATA_DIR . $MEDIA_DIRECTORY)));
		} else {
			FlashMessages::addMessage(I18N::translate('The folder %s does not exist, and it could not be created.', Html::filename(WT_DATA_DIR . $MEDIA_DIRECTORY)), 'danger');
			break;
		}
	}

	// Managers can create new media paths (subfolders). Users must use existing folders.
	if ($folderName && !is_dir(WT_DATA_DIR . $MEDIA_DIRECTORY . $folderName)) {
		if (Auth::isManager($WT_TREE)) {
			if (File::mkdir(WT_DATA_DIR . $MEDIA_DIRECTORY . $folderName)) {
				FlashMessages::addMessage(I18N::translate('The folder %s has been created.', Html::filename(WT_DATA_DIR . $MEDIA_DIRECTORY . $folderName)));
			} else {
				FlashMessages::addMessage(I18N::translate('The folder %s does not exist, and it could not be created.', Html::filename(WT_DATA_DIR . $MEDIA_DIRECTORY . $folderName)), 'danger');
				break;
			}
		} else {
			// Regular users should not have seen this option - so no need for an error message.
			break;
		}
	}

	// The media folder exists. Now create a thumbnail folder to match it.
	if (!is_dir(WT_DATA_DIR . $MEDIA_DIRECTORY . 'thumbs/' . $folderName)) {
		if (!File::mkdir(WT_DATA_DIR . $MEDIA_DIRECTORY . 'thumbs/' . $folderName)) {
			FlashMessages::addMessage(I18N::translate('The folder %s does not exist, and it could not be created.', Html::filename(WT_DATA_DIR . $MEDIA_DIRECTORY . 'thumbs/' . $folderName)), 'danger');
			break;
		}
	}

	// A thumbnail file with no main image?
	if (!empty($_FILES['thumbnail']['name']) && empty($_FILES['mediafile']['name'])) {
		// Assume the user used the wrong field, and treat this as a main image
		$_FILES['mediafile'] = $_FILES['thumbnail'];
		unset($_FILES['thumbnail']);
	}

	// Thumbnail files must contain images.
	if (!empty($_FILES['thumbnail']['name']) && !preg_match('/^image/', $_FILES['thumbnail']['type'])) {
		FlashMessages::addMessage(I18N::translate('Thumbnail files must contain images.'));
		break;
	}

	// User-specified filename?
	if ($tag[0] == 'FILE' && $text[0]) {
		$filename = $text[0];
	}
	// Use the name of the uploaded file?
	// If no filename specified, use the name of the uploaded file?
	if (!$filename && !empty($_FILES['mediafile']['name'])) {
		$filename = $_FILES['mediafile']['name'];
	}

	// Validate the media path and filename
	if (preg_match('/^https?:\/\//i', $text[0], $match)) {
		// External media needs no further validation
		$fileName   = $filename;
		$folderName = '';
		unset($_FILES['mediafile'], $_FILES['thumbnail']);
	} elseif (preg_match('/([\/\\\\<>])/', $filename, $match)) {
		// Local media files cannot contain certain special characters
		FlashMessages::addMessage(I18N::translate('Filenames are not allowed to contain the character “%s”.', $match[1]));
		break;
	} elseif (preg_match('/(\.(php|pl|cgi|bash|sh|bat|exe|com|htm|html|shtml))$/i', $filename, $match)) {
		// Do not allow obvious script files.
		FlashMessages::addMessage(I18N::translate('Filenames are not allowed to have the extension “%s”.', $match[1]));
		break;
	} elseif (!$filename) {
		FlashMessages::addMessage(I18N::translate('No media file was provided.'));
		break;
	} else {
		$fileName = $filename;
	}

	// Now copy the file to the correct location.
	if (!empty($_FILES['mediafile']['name'])) {
		$serverFileName = WT_DATA_DIR . $MEDIA_DIRECTORY . $folderName . $fileName;
		if (file_exists($serverFileName)) {
			FlashMessages::addMessage(I18N::translate('The file %s already exists. Use another filename.', $folderName . $fileName));
			break;
		}
		if (move_uploaded_file($_FILES['mediafile']['tmp_name'], $serverFileName)) {
			Log::addMediaLog('Media file ' . $serverFileName . ' uploaded');
		} else {
			FlashMessages::addMessage(
				I18N::translate('There was an error uploading your file.') .
				'<br>' .
				Functions::fileUploadErrorText($_FILES['mediafile']['error'])
			);
			break;
		}

		// Now copy the (optional) thumbnail
		if (!empty($_FILES['thumbnail']['name']) && preg_match('/^image\/(png|gif|jpeg)/', $_FILES['thumbnail']['type'], $match)) {
			// Thumbnails have either
			// (a) the same filename as the main image
			// (b) the same filename as the main image - but with a .png extension
			if ($match[1] == 'png' && !preg_match('/\.(png)$/i', $fileName)) {
				$thumbFile = preg_replace('/\.[a-z0-9]{3,5}$/', '.png', $fileName);
			} else {
				$thumbFile = $fileName;
			}
			$serverFileName = WT_DATA_DIR . $MEDIA_DIRECTORY . 'thumbs/' . $folderName . $thumbFile;
			if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $serverFileName)) {
				Log::addMediaLog('Thumbnail file ' . $serverFileName . ' uploaded');
			}
		}
	}

	$controller->pageHeader();
	// Build the gedcom record
	$newged = '0 @new@ OBJE';
	if ($tag[0] == 'FILE') {
		// The admin has an edit field to change the filename
		$text[0] = $folderName . $fileName;
	} else {
		// Users keep the original filename
		$newged .= "\n1 FILE " . $folderName . $fileName;
	}

	$newged = FunctionsEdit::handleUpdates($newged);

	$new_media = $WT_TREE->createRecord($newged);
	if ($linktoid) {
		$record = GedcomRecord::getInstance($linktoid, $WT_TREE);
		$record->createFact('1 OBJE @' . $new_media->getXref() . '@', true);
		Log::addEditLog('Media ID ' . $new_media->getXref() . ' successfully added to ' . $linktoid);
		$controller->addInlineJavascript('closePopupAndReloadParent();');
	} else {
		Log::addEditLog('Media ID ' . $new_media->getXref() . ' successfully added.');
	}
	echo '<button onclick="closePopupAndReloadParent();">', I18N::translate('close'), '</button>';

	return;

case 'showmediaform':
	$controller->setPageTitle(I18N::translate('Create a media object'));
	$action = 'create';
	break;
default:
	throw new \Exception('Bad $action (' . $action . ') in addmedia.php');
}

$controller->pageHeader();

echo '<div id="addmedia-page">'; //container for media edit pop-up
echo '<form method="post" name="newmedia" action="addmedia.php" enctype="multipart/form-data">';
echo '<input type="hidden" name="action" value="create">';
echo '<input type="hidden" name="ged" value="', $WT_TREE->getNameHtml(), '">';
echo '<input type="hidden" name="pid" value="', $pid, '">';
if ($linktoid) {
	echo '<input type="hidden" name="linktoid" value="', $linktoid, '">';
}
echo '<table class="table wt-facts-table">';
echo '<tr><td class="topbottombar" colspan="2">';
echo $controller->getPageTitle(), FunctionsPrint::helpLink('OBJE');
echo '</td></tr>';
if (!$linktoid) {
	echo '<tr><td class="descriptionbox wrap width25">';
	echo I18N::translate('Enter an individual, family, or source ID');
	echo '</td><td class="optionbox wrap"><input type="text" data-autocomplete-type="IFS" name="linktoid" id="linktoid" size="6" value="">';
	echo '<p class="small text-muted">', I18N::translate('Enter or search for the ID of the individual, family, or source to which this media object should be linked.'), '</p></td></tr>';
}

if ($media) {
	$gedrec = $media->getGedcom();
} else {
	$gedrec = '';
}

// 1 FILE
if (preg_match('/\n\d (FILE.*)/', $gedrec, $match)) {
	$gedfile = $match[1];
} elseif ($filename) {
	$gedfile = 'FILE ' . $filename;
} else {
	$gedfile = 'FILE';
}

if ($gedfile == 'FILE') {
	// Box for user to choose to upload file from local computer
	echo '<tr><td class="descriptionbox wrap width25">';
	echo I18N::translate('Media file to upload') . '</td><td class="optionbox wrap"><input type="file" name="mediafile" onchange="updateFormat(this.value);" size="40"></td></tr>';
	// Check for thumbnail generation support
	if (Auth::isManager($WT_TREE)) {
		echo '<tr><td class="descriptionbox wrap width25">';
		echo I18N::translate('Thumbnail to upload') . '</td><td class="optionbox wrap"><input type="file" name="thumbnail" size="40">';
		echo '<p class="small text-muted">', I18N::translate('Choose the thumbnail image that you want to upload. Although thumbnails can be generated automatically for images, you may wish to generate your own thumbnail, especially for other media types. For example, you can provide a still image from a video, or a photograph of the individual who made an audio recording.'), '</p>';
		echo '</td></tr>';
	}
}

// Filename on server
$isExternal = Functions::isFileExternal($gedfile);
if ($gedfile === 'FILE') {
	if (Auth::isManager($WT_TREE)) {
		echo FunctionsEdit::addSimpleTag(
			'1 FILE',
			'',
			I18N::translate('Filename on server'),
			'<p class="small text-muted">' . I18N::translate('Do not change to keep original filename.') . '<br>' . I18N::translate('You may enter a URL, beginning with “http://”.') . '</p>'
		);
	}
	$folder = '';
} else {
	if ($isExternal) {
		$fileName = substr($gedfile, 5);
		$folder   = '';
	} else {
		$tmp      = substr($gedfile, 5);
		$fileName = basename($tmp);
		$folder   = dirname($tmp);
		if ($folder === '.') {
			$folder = '';
		}
	}

	echo '<tr>';
	echo '<td class="descriptionbox wrap width25">';
	echo I18N::translate('Filename on server');
	echo '</td>';
	echo '<td class="optionbox wrap wrap">';
	if (Auth::isManager($WT_TREE)) {
		echo '<input name="filename" type="text" value="' . Html::escape($fileName) . '" size="40"';
		if ($isExternal) {
			echo '>';
		} else {
			echo '><p class="small text-muted">' . I18N::translate('Do not change to keep original filename.') . '</p>';
		}
	} else {
		echo $fileName;
		echo '<input name="filename" type="hidden" value="' . Html::escape($fileName) . '" size="40">';
	}
	echo '</td>';
	echo '</tr>';
}

// Box for user to choose the folder to store the image
if (!$isExternal) {
	echo '<tr><td class="descriptionbox wrap width25">';
	echo I18N::translate('Folder name on server'), '</td><td class="optionbox wrap">';
	//-- don’t let regular users change the location of media items
	if (Auth::isManager($WT_TREE)) {
		$mediaFolders = QueryMedia::folderList();
		echo '<select name="folder_list" onchange="document.newmedia.folder.value=this.options[this.selectedIndex].value;">';
		echo '<option ';
		if ($folder == '') {
			echo 'selected';
		}
		echo ' value=""> ', I18N::translate('Choose: '), ' </option>';
		if (Auth::isAdmin()) {
			echo '<option value="other" disabled>', I18N::translate('Other folder… please type in'), '</option>';
		}
		foreach ($mediaFolders as $f) {
			echo '<option value="', $f, '" ';
			if ($folder == $f) {
				echo 'selected';
			}
			echo '>', $f, '</option>';
		}
		echo '</select>';
	} else {
		echo $folder;
	}
	if (Auth::isAdmin()) {
		echo '<br><input type="text" name="folder" size="40" value="', $folder, '">';
		if ($gedfile === 'FILE') {
			echo '<p class="small text-muted">', I18N::translate('This entry is ignored if you have entered a URL into the filename field.'), '</p>';
		}
	} else {
		echo '<input name="folder" type="hidden" value="', Html::escape($folder), '">';
	}
	echo '<p class="small text-muted">', I18N::translate('If you have a large number of media files, you can organize them into folders and subfolders.'), '</p>';
	echo '</td></tr>';
} else {
	echo '<input name="folder" type="hidden" value="">';
}

// 1 FILE / 2 FORM
if (preg_match('/\n(2 FORM .*)/', $gedrec, $match)) {
	$gedform = $match[1];
} else {
	$gedform = '2 FORM';
}
echo FunctionsEdit::addSimpleTag($gedform);

// 1 FILE / 2 FORM / 3 TYPE
if (preg_match('/\n(3 TYPE .*)/', $gedrec, $match)) {
	$gedtype = $match[1];
} else {
	$gedtype = '3 TYPE photo'; // default to ‘Photo’
}
echo FunctionsEdit::addSimpleTag($gedtype);

// 1 FILE / 2 TITL
if (preg_match('/\n(2 TITL .*)/', $gedrec, $match)) {
	$gedtitl = $match[1];
} else {
	$gedtitl = '2 TITL';
}
echo FunctionsEdit::addSimpleTag($gedtitl);

// 1 FILE / 2 TITL / 3 _HEB
if (strstr($WT_TREE->getPreference('ADVANCED_NAME_FACTS'), '_HEB') !== false) {
	if (preg_match('/\n(3 _HEB .*)/', $gedrec, $match)) {
		$gedtitl = $match[1];
	} else {
		$gedtitl = '3 _HEB';
	}
	echo FunctionsEdit::addSimpleTag($gedtitl);
}

// 1 FILE / 2 TITL / 3 ROMN
if (strstr($WT_TREE->getPreference('ADVANCED_NAME_FACTS'), 'ROMN') !== false) {
	if (preg_match('/\n(3 ROMN .*)/', $gedrec, $match)) {
		$gedtitl = $match[1];
	} else {
		$gedtitl = '3 ROMN';
	}
	echo FunctionsEdit::addSimpleTag($gedtitl);
}

// 1 _PRIM
if (preg_match('/\n(1 _PRIM .*)/', $gedrec, $match)) {
	$gedprim = $match[1];
} else {
	$gedprim = '1 _PRIM';
}
echo FunctionsEdit::addSimpleTag($gedprim);

//-- print out editing fields for any other data in the media record
$sourceLevel = 0;
$sourceSOUR  = '';
$sourcePAGE  = '';
$sourceTEXT  = '';
$sourceDATE  = '';
$sourceQUAY  = '';
if (!empty($gedrec)) {
	preg_match_all('/\n(1 (?!FILE|FORM|TYPE|TITL|_PRIM|_THUM|CHAN|DATA).*(\n[2-9] .*)*)/', $gedrec, $matches);
	foreach ($matches[1] as $subrec) {
		$pieces = explode("\n", $subrec);
		foreach ($pieces as $piece) {
			$ft = preg_match("/(\d) (\w+)(.*)/", $piece, $match);
			if ($ft == 0) {
				continue;
			}
			$subLevel = $match[1];
			$fact     = trim($match[2]);
			$event    = trim($match[3]);
			if ($fact === 'NOTE' || $fact === 'TEXT') {
				$event .= Functions::getCont($subLevel + 1, $subrec);
			}
			if ($sourceSOUR !== '' && $subLevel <= $sourceLevel) {
				// Get rid of all saved Source data
				echo FunctionsEdit::addSimpleTag($sourceLevel . ' SOUR ' . $sourceSOUR);
				echo FunctionsEdit::addSimpleTag(($sourceLevel + 1) . ' PAGE ' . $sourcePAGE);
				echo FunctionsEdit::addSimpleTag(($sourceLevel + 2) . ' TEXT ' . $sourceTEXT);
				echo FunctionsEdit::addSimpleTag(($sourceLevel + 2) . ' DATE ' . $sourceDATE, '', GedcomTag::getLabel('DATA:DATE'));
				echo FunctionsEdit::addSimpleTag(($sourceLevel + 1) . ' QUAY ' . $sourceQUAY);
				$sourceSOUR = '';
			}

			if ($fact === 'SOUR') {
				$sourceLevel = $subLevel;
				$sourceSOUR  = $event;
				$sourcePAGE  = '';
				$sourceTEXT  = '';
				$sourceDATE  = '';
				$sourceQUAY  = '';
				continue;
			}

			// Save all incoming data about this source reference
			if ($sourceSOUR !== '') {
				if ($fact === 'PAGE') {
					$sourcePAGE = $event;
					continue;
				}
				if ($fact === 'TEXT') {
					$sourceTEXT = $event;
					continue;
				}
				if ($fact === 'DATE') {
					$sourceDATE = $event;
					continue;
				}
				if ($fact === 'QUAY') {
					$sourceQUAY = $event;
					continue;
				}
				continue;
			}

			// Output anything that isn’t part of a source reference
			if (!empty($fact) && $fact !== 'CONC' && $fact !== 'CONT' && $fact !== 'DATA') {
				echo FunctionsEdit::addSimpleTag($subLevel . ' ' . $fact . ' ' . $event);
			}
		}
	}

	if ($sourceSOUR !== '') {
		// Get rid of all saved Source data
		echo FunctionsEdit::addSimpleTag($sourceLevel . ' SOUR ' . $sourceSOUR);
		echo FunctionsEdit::addSimpleTag(($sourceLevel + 1) . ' PAGE ' . $sourcePAGE);
		echo FunctionsEdit::addSimpleTag(($sourceLevel + 2) . ' TEXT ' . $sourceTEXT);
		echo FunctionsEdit::addSimpleTag(($sourceLevel + 2) . ' DATE ' . $sourceDATE, '', GedcomTag::getLabel('DATA:DATE'));
		echo FunctionsEdit::addSimpleTag(($sourceLevel + 1) . ' QUAY ' . $sourceQUAY);
	}
}
echo '</table>';
FunctionsEdit::printAddLayer('SOUR', 1);
FunctionsEdit::printAddLayer('NOTE', 1);
FunctionsEdit::printAddLayer('SHARED_NOTE', 1);
FunctionsEdit::printAddLayer('RESN', 1);
?>
		<div class="row form-group">
			<div class="col-sm-9 offset-sm-3">
				<button class="btn btn-primary" type="submit">
					<?= FontAwesome::decorativeIcon('save') ?>
					<?= /* I18N: A button label. */ I18N::translate('save') ?>
				</button>
				<a class="btn btn-secondary" href="<?= $media->getHtmlUrl() ?>">
					<?= FontAwesome::decorativeIcon('cancel') ?>
					<?= /* I18N: A button label. */ I18N::translate('cancel') ?>
				</a>
			</div>
		</div>
	</form>
</div>
