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
namespace Fisharebest\Webtrees\Module;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Bootstrap4;
use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\Html;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module;
use Fisharebest\Webtrees\Tree;

/**
 * Class StoriesModule
 */
class StoriesModule extends AbstractModule implements ModuleTabInterface, ModuleConfigInterface, ModuleMenuInterface {
	/** {@inheritdoc} */
	public function getTitle() {
		return /* I18N: Name of a module */ I18N::translate('Stories');
	}

	/** {@inheritdoc} */
	public function getDescription() {
		return /* I18N: Description of the “Stories” module */ I18N::translate('Add narrative stories to individuals in the family tree.');
	}

	/**
	 * This is a general purpose hook, allowing modules to respond to routes
	 * of the form module.php?mod=FOO&mod_action=BAR
	 *
	 * @param string $mod_action
	 */
	public function modAction($mod_action) {
		switch ($mod_action) {
		case 'admin_edit':
			$this->edit();
			break;
		case 'admin_delete':
			$this->delete();
			$this->config();
			break;
		case 'admin_config':
			$this->config();
			break;
		case 'show_list':
			$this->showList();
			break;
		default:
			http_response_code(404);
		}
	}

	/** {@inheritdoc} */
	public function getConfigLink() {
		return Html::url('module.php', [
			'mod'        => $this->getName(),
			'mod_action' => 'admin_config',
		]);
	}

	/** {@inheritdoc} */
	public function defaultTabOrder() {
		return 55;
	}

	/** {@inheritdoc} */
	public function getTabContent() {
		global $controller, $WT_TREE;

		$block_ids =
			Database::prepare(
				"SELECT block_id" .
				" FROM `##block`" .
				" WHERE module_name=?" .
				" AND xref=?" .
				" AND gedcom_id=?"
			)->execute([
				$this->getName(),
				$controller->record->getXref(),
				$controller->record->getTree()->getTreeId(),
			])->fetchOneColumn();

		$html = '';
		foreach ($block_ids as $block_id) {
			// Only show this block for certain languages
			$languages = $this->getBlockSetting($block_id, 'languages');
			if (!$languages || in_array(WT_LOCALE, explode(',', $languages))) {
				$html .= '<div class="story_title descriptionbox center rela">' . $this->getBlockSetting($block_id, 'title') . '</div>';
				$html .= '<div class="story_body optionbox">' . $this->getBlockSetting($block_id, 'story_body') . '</div>';
				if (Auth::isEditor($WT_TREE)) {
					$html .= '<div class="story_edit"><a href="module.php?mod=' . $this->getName() . '&amp;mod_action=admin_edit&amp;block_id=' . $block_id . '">';
					$html .= I18N::translate('Edit the story') . '</a></div>';
				}
			}
		}
		if (Auth::isManager($WT_TREE) && !$html) {
			$html .= '<div class="news_title center">' . $this->getTitle() . '</div>';
			$html .= '<div><a href="module.php?mod=' . $this->getName() . '&amp;mod_action=admin_edit&amp;xref=' . $controller->record->getXref() . '">';
			$html .= I18N::translate('Add a story') . '</a></div><br>';
		}

		return $html;
	}

	/** {@inheritdoc} */
	public function hasTabContent() {
		return $this->getTabContent() != '';
	}

	/** {@inheritdoc} */
	public function isGrayedOut() {
		global $controller;

		$count_of_stories =
			Database::prepare(
				"SELECT COUNT(block_id)" .
				" FROM `##block`" .
				" WHERE module_name=?" .
				" AND xref=?" .
				" AND gedcom_id=?"
			)->execute([
				$this->getName(),
				$controller->record->getXref(),
				$controller->record->getTree()->getTreeId(),
			])->fetchOne();

		return $count_of_stories == 0;
	}

	/** {@inheritdoc} */
	public function canLoadAjax() {
		return false;
	}

	/** {@inheritdoc} */
	public function getPreLoadContent() {
		return '';
	}

	/**
	 * Show and process a form to edit a story.
	 */
	private function edit() {
		global $WT_TREE;

		if (Auth::isEditor($WT_TREE)) {
			if (Filter::postBool('save') && Filter::checkCsrf()) {
				$block_id = Filter::postInteger('block_id');
				if ($block_id) {
					Database::prepare(
						"UPDATE `##block` SET gedcom_id=?, xref=? WHERE block_id=?"
					)->execute([Filter::postInteger('gedcom_id'), Filter::post('xref', WT_REGEX_XREF), $block_id]);
				} else {
					Database::prepare(
						"INSERT INTO `##block` (gedcom_id, xref, module_name, block_order) VALUES (?, ?, ?, ?)"
					)->execute([
						Filter::postInteger('gedcom_id'),
						Filter::post('xref', WT_REGEX_XREF),
						$this->getName(),
						0,
					]);
					$block_id = Database::getInstance()->lastInsertId();
				}
				$this->setBlockSetting($block_id, 'title', Filter::post('title'));
				$this->setBlockSetting($block_id, 'story_body', Filter::post('story_body'));
				$languages = Filter::postArray('lang');
				$this->setBlockSetting($block_id, 'languages', implode(',', $languages));
				$this->config();
			} else {
				$block_id = Filter::getInteger('block_id');

				$controller = new PageController;
				if ($block_id) {
					$controller->setPageTitle(I18N::translate('Edit the story'));
					$title      = $this->getBlockSetting($block_id, 'title');
					$story_body = $this->getBlockSetting($block_id, 'story_body');
					$xref       = Database::prepare(
						"SELECT xref FROM `##block` WHERE block_id=?"
					)->execute([$block_id])->fetchOne();
				} else {
					$controller->setPageTitle(I18N::translate('Add a story'));
					$title      = '';
					$story_body = '';
					$xref       = Filter::get('xref', WT_REGEX_XREF);
				}
				$controller->pageHeader();
				if (Module::getModuleByName('ckeditor')) {
					CkeditorModule::enableEditor($controller);
				}

				$individual = Individual::getInstance($xref, $WT_TREE);

				echo Bootstrap4::breadcrumbs([
					route('admin-control-panel') => I18N::translate('Control panel'),
					route('admin-modules')       => I18N::translate('Module administration'),
					'module.php?mod=' . $this->getName() . '&mod_action=admin_config' => $this->getTitle(),
				], $controller->getPageTitle());
				?>

				<h1><?= $controller->getPageTitle() ?></h1>

				<form class="form-horizontal" method="post" action="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_edit">
					<?= Filter::getCsrf() ?>
					<input type="hidden" name="save" value="1">
					<input type="hidden" name="block_id" value="<?= $block_id ?>">
					<input type="hidden" name="gedcom_id" value="<?= $WT_TREE->getTreeId() ?>">

					<div class="row form-group">
						<label for="title" class="col-sm-3 col-form-label">
							<?= I18N::translate('Story title') ?>
						</label>
						<div class="col-sm-9">
							<input type="text" class="form-control" name="title" id="title" value="<?= Html::escape($title) ?>">
						</div>
					</div>

					<div class="row form-group">
						<label for="story_body" class="col-sm-3 col-form-label">
							<?= I18N::translate('Story') ?>
						</label>
						<div class="col-sm-9">
							<textarea name="story_body" id="story_body" class="html-edit form-control" rows="10"><?= Html::escape($story_body) ?></textarea>
						</div>
					</div>

					<div class="row form-group">
						<label for="xref" class="col-sm-3 col-form-label">
							<?= I18N::translate('Individual') ?>
						</label>
						<div class="col-sm-9">
							<?= FunctionsEdit::formControlIndividual($individual, ['id' => 'xref', 'name' => 'xref']) ?>
						</div>
					</div>

					<div class="row form-group">
						<label for="xref" class="col-sm-3 col-form-label">
							<?= I18N::translate('Show this block for which languages') ?>
						</label>
						<div class="col-sm-9">
							<?= FunctionsEdit::editLanguageCheckboxes('lang', explode(',', $this->getBlockSetting($block_id, 'languages'))) ?>
						</div>
					</div>

					<div class="row form-group">
						<div class="offset-sm-3 col-sm-9">
							<button type="submit" class="btn btn-primary">
								<i class="fa fa-check"></i>
								<?= I18N::translate('save') ?>
							</button>
						</div>
					</div>

				</form>
				<?php
			}
		} else {
			header('Location: index.php');
		}
	}

	/**
	 * Respond to a request to delete a story.
	 */
	private function delete() {
		global $WT_TREE;

		if (Auth::isEditor($WT_TREE)) {
			$block_id = Filter::getInteger('block_id');

			Database::prepare(
				"DELETE FROM `##block_setting` WHERE block_id=?"
			)->execute([$block_id]);

			Database::prepare(
				"DELETE FROM `##block` WHERE block_id=?"
			)->execute([$block_id]);
		} else {
			header('Location: index.php');
			exit;
		}
	}

	/**
	 * The admin view - list, create, edit, delete stories.
	 */
	private function config() {
		global $WT_TREE;

		$controller = new PageController;
		$controller
			->restrictAccess(Auth::isAdmin())
			->setPageTitle($this->getTitle())
			->pageHeader()
			->addInlineJavascript('
				$("#story_table").dataTable({
					' . I18N::datatablesI18N() . ',
					autoWidth: false,
					paging: true,
					pagingType: "full_numbers",
					lengthChange: true,
					filter: true,
					info: true,
					sorting: [[0,"asc"]],
					columns: [
						/* 0-name */ null,
						/* 1-NAME */ null,
						/* 2-NAME */ { sortable:false },
						/* 3-NAME */ { sortable:false }
					]
				});
			');

		$stories = Database::prepare(
			"SELECT block_id, xref" .
			" FROM `##block` b" .
			" WHERE module_name=?" .
			" AND gedcom_id=?" .
			" ORDER BY xref"
		)->execute([$this->getName(), $WT_TREE->getTreeId()])->fetchAll();

		echo Bootstrap4::breadcrumbs([
			route('admin-control-panel') => I18N::translate('Control panel'),
			route('admin-modules')       => I18N::translate('Module administration'),
		], $controller->getPageTitle());
		?>

		<h1><?= $controller->getPageTitle() ?></h1>

		<form class="form form-inline">
			<label for="ged" class="sr-only">
				<?= I18N::translate('Family tree') ?>
			</label>
			<input type="hidden" name="mod" value="<?=  $this->getName() ?>">
			<input type="hidden" name="mod_action" value="admin_config">
			<?= Bootstrap4::select(Tree::getNameList(), $WT_TREE->getName(), ['id' => 'ged', 'name' => 'ged']) ?>
			<input type="submit" class="btn btn-primary" value="<?= I18N::translate('show') ?>">
		</form>

		<p>
			<a href="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_edit" class="btn btn-default">
				<i class="fa fa-plus"></i>
				<?= I18N::translate('Add a story') ?>
			</a>
		</p>

		<table class="table table-bordered table-sm">
			<thead>
				<tr>
					<th><?= I18N::translate('Story title') ?></th>
					<th><?= I18N::translate('Individual') ?></th>
					<th><?= I18N::translate('Edit') ?></th>
					<th><?= I18N::translate('Delete') ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($stories as $story): ?>
				<tr>
					<td>
						<?= Html::escape($this->getBlockSetting($story->block_id, 'title')) ?>
					</td>
					<td>
						<?php $individual = Individual::getInstance($story->xref, $WT_TREE) ?>
						<?php if ($individual): ?>
						<a href="<?= $individual->getHtmlUrl() ?>#tab-stories">
							<?= $individual->getFullName() ?>
						</a>
						<?php else: ?>
							<?= $story->xref ?>
						<?php endif ?>
						</td>
						<td>
							<a href="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_edit&amp;block_id=<?= $story->block_id ?>">
								<i class="fa fa-pencil"></i> <?= I18N::translate('Edit') ?>
							</a>
						</td>
						<td>
							<a
								href="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_delete&amp;block_id=<?= $story->block_id ?>"
								onclick="return confirm('<?= I18N::translate('Are you sure you want to delete “%s”?', Html::escape($this->getBlockSetting($story->block_id, 'title'))) ?>');"
							>
								<i class="fa fa-trash"></i> <?= I18N::translate('Delete') ?>
							</a>
					</td>
				</tr>
				<?php endforeach ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show the list of stories
	 */
	private function showList() {
		global $controller, $WT_TREE;

		$controller = new PageController;
		$controller
			->setPageTitle($this->getTitle())
			->pageHeader()
			->addInlineJavascript('
				$("#story_table").dataTable({
					dom: \'<"H"pf<"dt-clear">irl>t<"F"pl>\',
					' . I18N::datatablesI18N() . ',
					autoWidth: false,
					paging: true,
					pagingType: "full_numbers",
					lengthChange: true,
					filter: true,
					info: true,
					sorting: [[0,"asc"]],
					columns: [
						/* 0-name */ null,
						/* 1-NAME */ null
					]
				});
			');

		$stories = Database::prepare(
			"SELECT block_id, xref" .
			" FROM `##block` b" .
			" WHERE module_name=?" .
			" AND gedcom_id=?" .
			" ORDER BY xref"
		)->execute([$this->getName(), $WT_TREE->getTreeId()])->fetchAll();

		echo '<h2 class="wt-page-title">', I18N::translate('Stories'), '</h2>';
		if (count($stories) > 0) {
			echo '<table id="story_table" class="width100">';
			echo '<thead><tr>
				<th>', I18N::translate('Story title'), '</th>
				<th>', I18N::translate('Individual'), '</th>
				</tr></thead>
				<tbody>';
			foreach ($stories as $story) {
				$indi        = Individual::getInstance($story->xref, $WT_TREE);
				$story_title = $this->getBlockSetting($story->block_id, 'title');
				$languages   = $this->getBlockSetting($story->block_id, 'languages');
				if (!$languages || in_array(WT_LOCALE, explode(',', $languages))) {
					if ($indi) {
						if ($indi->canShow()) {
							echo '<tr><td><a href="' . $indi->getHtmlUrl() . '#tab-stories">' . $story_title . '</a></td><td><a href="' . $indi->getHtmlUrl() . '#tab-stories">' . $indi->getFullName() . '</a></td></tr>';
						}
					} else {
						echo '<tr><td>', $story_title, '</td><td class="error">', $story->xref, '</td></tr>';
					}
				}
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * The user can re-order menus. Until they do, they are shown in this order.
	 *
	 * @return int
	 */
	public function defaultMenuOrder() {
		return 30;
	}

	/**
	 * What is the default access level for this module?
	 *
	 * Some modules are aimed at admins or managers, and are not generally shown to users.
	 *
	 * @return int
	 */
	public function defaultAccessLevel() {
		return Auth::PRIV_HIDE;
	}

	/**
	 * A menu, to be added to the main application menu.
	 *
	 * @return Menu|null
	 */
	public function getMenu() {
		$menu = new Menu($this->getTitle(), 'module.php?mod=' . $this->getName() . '&amp;mod_action=show_list', 'menu-story');

		return $menu;
	}
}
