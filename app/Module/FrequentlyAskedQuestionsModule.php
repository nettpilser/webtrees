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
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module;
use Fisharebest\Webtrees\Tree;

/**
 * Class FrequentlyAskedQuestionsModule
 */
class FrequentlyAskedQuestionsModule extends AbstractModule implements ModuleMenuInterface, ModuleConfigInterface {
	/** {@inheritdoc} */
	public function getTitle() {
		return /* I18N: Name of a module. Abbreviation for “Frequently Asked Questions” */ I18N::translate('FAQ');
	}

	/** {@inheritdoc} */
	public function getDescription() {
		return /* I18N: Description of the “FAQ” module */ I18N::translate('A list of frequently asked questions and answers.');
	}

	/**
	 * This is a general purpose hook, allowing modules to respond to routes
	 * of the form module.php?mod=FOO&mod_action=BAR
	 *
	 * @param string $mod_action
	 */
	public function modAction($mod_action) {
		switch ($mod_action) {
		case 'admin_config':
			$this->config();
			break;
		case 'admin_delete':
			if (Auth::isAdmin()) {
				$this->delete();
			}
			header('Location: module.php?mod=faq&mod_action=admin_config');
			break;
		case 'admin_edit':
			$this->edit();
			break;
		case 'admin_edit_save':
			if (Auth::isAdmin()) {
				$this->editSave();
			}
			header('Location: module.php?mod=faq&mod_action=admin_config');
			break;
		case 'admin_movedown':
			if (Auth::isAdmin()) {
				$this->movedown();
			}
			header('Location: module.php?mod=faq&mod_action=admin_config');
			break;
		case 'admin_moveup':
			if (Auth::isAdmin()) {
				$this->moveup();
			}
			header('Location: module.php?mod=faq&mod_action=admin_config');
			break;
		case 'show':
			$this->show();
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

	/**
	 * Action from the configuration page
	 */
	private function editSave() {
		if (Filter::checkCsrf()) {
			$block_id = Filter::postInteger('block_id');
			if ($block_id) {
				Database::prepare(
					"UPDATE `##block` SET gedcom_id = NULLIF(:tree_id, '0'), block_order = :block_order WHERE block_id = :block_id"
				)->execute([
					'tree_id'     => Filter::postInteger('gedcom_id'),
					'block_order' => Filter::postInteger('block_order'),
					'block_id'    => $block_id,
				]);
			} else {
				Database::prepare(
					"INSERT INTO `##block` (gedcom_id, module_name, block_order) VALUES (NULLIF(:tree_id, '0'), :module_name, :block_order)"
				)->execute([
					'tree_id'     => Filter::postInteger('gedcom_id'),
					'module_name' => $this->getName(),
					'block_order' => Filter::postInteger('block_order'),
				]);
				$block_id = Database::getInstance()->lastInsertId();
			}
			$this->setBlockSetting($block_id, 'header', Filter::post('header'));
			$this->setBlockSetting($block_id, 'faqbody', Filter::post('faqbody'));

			$languages = Filter::postArray('lang');
			$this->setBlockSetting($block_id, 'languages', implode(',', $languages));
		}
	}

	/**
	 * Action from the configuration page
	 */
	private function edit() {
		global $WT_TREE;

		$controller = new PageController;
		$controller->restrictAccess(Auth::isAdmin());

		$block_id = Filter::getInteger('block_id');
		if ($block_id) {
			$controller->setPageTitle(/* I18N: FAQ = “Frequently Asked Question” */ I18N::translate('Edit the FAQ'));
			$header      = $this->getBlockSetting($block_id, 'header');
			$faqbody     = $this->getBlockSetting($block_id, 'faqbody');
			$block_order = Database::prepare(
				"SELECT block_order FROM `##block` WHERE block_id = :block_id"
			)->execute(['block_id' => $block_id])->fetchOne();
			$gedcom_id = Database::prepare(
				"SELECT gedcom_id FROM `##block` WHERE block_id = :block_id"
			)->execute(['block_id' => $block_id])->fetchOne();
		} else {
			$controller->setPageTitle(/* I18N: FAQ = “Frequently Asked Question” */ I18N::translate('Add an FAQ'));
			$header      = '';
			$faqbody     = '';
			$block_order = Database::prepare(
				"SELECT IFNULL(MAX(block_order)+1, 0) FROM `##block` WHERE module_name = :module_name"
			)->execute(['module_name' => $this->getName()])->fetchOne();
			$gedcom_id = $WT_TREE->getTreeId();
		}
		$controller->pageHeader();
		if (Module::getModuleByName('ckeditor')) {
			CkeditorModule::enableEditor($controller);
		}

		echo Bootstrap4::breadcrumbs([
			route('admin-control-panel')                                      => I18N::translate('Control panel'),
			route('admin-modules')                                            => I18N::translate('Module administration'),
			'module.php?mod=' . $this->getName() . '&mod_action=admin_config' => $this->getTitle(),
		], $controller->getPageTitle());
		?>

		<h1><?= $controller->getPageTitle() ?></h1>

		<form name="faq" class="form-horizontal" method="post" action="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_edit_save">
		<?= Filter::getCsrf() ?>
		<input type="hidden" name="block_id" value="<?= $block_id ?>">

		<div class="row form-group">
			<label for="header" class="col-sm-3 col-form-label">
				<?= I18N::translate('Question') ?>
			</label>

			<div class="col-sm-9">
				<input type="text" class="form-control" name="header" id="header"
				       value="<?= Html::escape($header) ?>">
			</div>
		</div>

		<div class="row form-group">
			<label for="faqbody" class="col-sm-3 col-form-label">
				<?= I18N::translate('Answer') ?>
			</label>

			<div class="col-sm-9">
				<textarea name="faqbody" id="faqbody" class="form-control html-edit" rows="10"><?= Html::escape($faqbody) ?></textarea>
			</div>
		</div>

		<div class="row form-group">
			<label for="xref" class="col-sm-3 col-form-label">
				<?= /* I18N: Label for a configuration option */ I18N::translate('Show this block for which languages') ?>
			</label>

			<div class="col-sm-9">
				<?= FunctionsEdit::editLanguageCheckboxes('lang', explode(',', $this->getBlockSetting($block_id, 'languages'))) ?>
			</div>
		</div>

		<div class="row form-group">
			<label for="block_order" class="col-sm-3 col-form-label">
				<?= I18N::translate('Sort order') ?>
			</label>

			<div class="col-sm-9">
				<input type="text" name="block_order" id="block_order" class="form-control" value="<?= $block_order ?>">
			</div>
		</div>

		<div class="row form-group">
			<label for="gedcom_id" class="col-sm-3 col-form-label">
				<?= I18N::translate('Family tree') ?>
			</label>

			<div class="col-sm-9">
				<?= Bootstrap4::select(['' => I18N::translate('All')] + Tree::getIdList(), $gedcom_id, ['id' => 'gedcom_id', 'name' => 'gedcom_id']) ?>
				<p class="small text-muted">
					<?= /* I18N: FAQ = “Frequently Asked Question” */ I18N::translate('An FAQ can be displayed on just one of the family trees, or on all the family trees.') ?>
				</p>
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

	/**
	 * Delete an FAQ.
	 */
	private function delete() {
		$block_id = Filter::getInteger('block_id');

		Database::prepare(
			"DELETE FROM `##block_setting` WHERE block_id = :block_id"
		)->execute(['block_id' => $block_id]);

		Database::prepare(
			"DELETE FROM `##block` WHERE block_id = :block_id"
		)->execute(['block_id' => $block_id]);
	}

	/**
	 * Move an FAQ up the list.
	 */
	private function moveup() {
		$block_id = Filter::getInteger('block_id');

		$block_order = Database::prepare(
			"SELECT block_order FROM `##block` WHERE block_id = :block_id"
		)->execute(['block_id' => $block_id])->fetchOne();

		$swap_block = Database::prepare(
			"SELECT block_order, block_id" .
			" FROM `##block`" .
			" WHERE block_order = (" .
			"  SELECT MAX(block_order) FROM `##block` WHERE block_order < :block_order AND module_name = :module_name_1" .
			" ) AND module_name = :module_name_2" .
			" LIMIT 1"
		)->execute([
			'block_order'   => $block_order,
			'module_name_1' => $this->getName(),
			'module_name_2' => $this->getName(),
		])->fetchOneRow();
		if ($swap_block) {
			Database::prepare(
				"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
			)->execute([
				'block_order' => $swap_block->block_order,
				'block_id'    => $block_id,
			]);
			Database::prepare(
				"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
			)->execute([
				'block_order' => $block_order,
				'block_id'    => $swap_block->block_id,
			]);
		}
	}

	/**
	 * Move an FAQ down the list.
	 */
	private function movedown() {
		$block_id = Filter::get('block_id');

		$block_order = Database::prepare(
			"SELECT block_order FROM `##block` WHERE block_id = :block_id"
		)->execute([
			'block_id' => $block_id,
		])->fetchOne();

		$swap_block = Database::prepare(
			"SELECT block_order, block_id" .
			" FROM `##block`" .
			" WHERE block_order=(" .
			"  SELECT MIN(block_order) FROM `##block` WHERE block_order > :block_order AND module_name = :module_name_1" .
			" ) AND module_name = :module_name_2" .
			" LIMIT 1"
		)->execute([
			'block_order'   => $block_order,
			'module_name_1' => $this->getName(),
			'module_name_2' => $this->getName(),
			])->fetchOneRow();
		if ($swap_block) {
			Database::prepare(
				"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
			)->execute([
				'block_order' => $swap_block->block_order,
				'block_id'    => $block_id,
			]);
			Database::prepare(
				"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
			)->execute([
				'block_order' => $block_order,
				'block_id'    => $swap_block->block_id,
			]);
		}
	}

	/**
	 * Show a list of FAQs
	 */
	private function show() {
		global $controller, $WT_TREE;

		$controller = new PageController;
		$controller
			->setPageTitle(I18N::translate('Frequently asked questions'))
			->pageHeader();

		$faqs = Database::prepare(
			"SELECT block_id, bs1.setting_value AS header, bs2.setting_value AS body, bs3.setting_value AS languages" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" JOIN `##block_setting` bs3 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name = 'header'" .
			" AND bs2.setting_name = 'faqbody'" .
			" AND bs3.setting_name = 'languages'" .
			" AND IFNULL(gedcom_id, :tree_id_1) = :tree_id_2" .
			" ORDER BY block_order"
		)->execute([
			'module_name' => $this->getName(),
			'tree_id_1'   => $WT_TREE->getTreeId(),
			'tree_id_2'   => $WT_TREE->getTreeId(),
		])->fetchAll();

		echo '<h2 class="wt-page-title">', I18N::translate('Frequently asked questions');
		if (Auth::isManager($WT_TREE)) {
			echo ' — <a href="module.php?mod=', $this->getName(), '&amp;mod_action=admin_config">', I18N::translate('edit'), '</a>';
		}
		echo '</h2>';
		$row_count = 0;
		echo '<table class="faq">';
		// List of titles
		foreach ($faqs as $id => $faq) {
			if (!$faq->languages || in_array(WT_LOCALE, explode(',', $faq->languages))) {
				$row_color = ($row_count % 2) ? 'odd' : 'even';
				// NOTE: Print the header of the current item
				echo '<tr class="', $row_color, '"><td style="padding: 5px;">';
				echo '<a href="#faq', $id, '">', $faq->header, '</a>';
				echo '</td></tr>';
				$row_count++;
			}
		}
		echo '</table><hr>';
		// Detailed entries
		foreach ($faqs as $id => $faq) {
			if (!$faq->languages || in_array(WT_LOCALE, explode(',', $faq->languages))) {
				echo '<div class="faq_title" id="faq', $id, '">', $faq->header;
				echo '<div class="faq_top faq_italic">';
				echo '<a href="#content">', I18N::translate('back to top'), '</a>';
				echo '</div>';
				echo '</div>';
				echo '<div class="faq_body">', substr($faq->body, 0, 1) == '<' ? $faq->body : nl2br($faq->body, false), '</div>';
				echo '<hr>';
			}
		}
	}

	/**
	 * Provide a form to manage the FAQs.
	 */
	private function config() {
		global $WT_TREE;

		$controller = new PageController;
		$controller
			->restrictAccess(Auth::isAdmin())
			->setPageTitle(I18N::translate('Frequently asked questions'))
			->pageHeader();

		$faqs = Database::prepare(
			"SELECT block_id, block_order, gedcom_id, bs1.setting_value AS header, bs2.setting_value AS faqbody" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name = 'header'" .
			" AND bs2.setting_name = 'faqbody'" .
			" AND IFNULL(gedcom_id, :tree_id_1) = :tree_id_2" .
			" ORDER BY block_order"
		)->execute([
			'module_name' => $this->getName(),
			'tree_id_1'   => $WT_TREE->getTreeId(),
			'tree_id_2'   => $WT_TREE->getTreeId(),
			])->fetchAll();

		$min_block_order = Database::prepare(
			"SELECT MIN(block_order) FROM `##block` WHERE module_name = 'faq' AND (gedcom_id = :tree_id OR gedcom_id IS NULL)"
		)->execute([
			'tree_id' => $WT_TREE->getTreeId(),
		])->fetchOne();

		$max_block_order = Database::prepare(
			"SELECT MAX(block_order) FROM `##block` WHERE module_name = 'faq' AND (gedcom_id = :tree_id OR gedcom_id IS NULL)"
		)->execute([
			'tree_id' => $WT_TREE->getTreeId(),
		])->fetchOne();

		echo Bootstrap4::breadcrumbs([
			route('admin-control-panel') => I18N::translate('Control panel'),
			route('admin-modules')       => I18N::translate('Module administration'),
		], $controller->getPageTitle());
		?>

		<h1><?= $controller->getPageTitle() ?></h1>
		<p>
			<?= /* I18N: FAQ = “Frequently Asked Question” */ I18N::translate('FAQs are lists of questions and answers, which allow you to explain the site’s rules, policies, and procedures to your visitors. Questions are typically concerned with privacy, copyright, user-accounts, unsuitable content, requirement for source-citations, etc.') ?>
			<?= I18N::translate('You may use HTML to format the answer and to add links to other websites.') ?>
		</p>

		<p>
			<form class="form form-inline">
				<label for="ged" class="sr-only">
					<?= I18N::translate('Family tree') ?>
				</label>
				<input type="hidden" name="mod" value="<?=  $this->getName() ?>">
				<input type="hidden" name="mod_action" value="admin_config">
				<?= Bootstrap4::select(Tree::getNameList(), $WT_TREE->getName(), ['id' => 'ged', 'name' => 'ged']) ?>
				<input type="submit" class="btn btn-primary" value="<?= I18N::translate('show') ?>">
			</form>
		</p>

		<p>
			<a href="module.php?mod=<?= $this->getName() ?>&amp;mod_action=admin_edit" class="btn btn-default">
				<i class="fa fa-plus"></i>
				<?= /* I18N: FAQ = “Frequently Asked Question” */ I18N::translate('Add an FAQ') ?>
			</a>
		</p>

		<?php
		echo '<table class="table table-bordered">';
		foreach ($faqs as $faq) {
			// NOTE: Print the position of the current item
			echo '<tr class="faq_edit_pos"><td>';
			echo I18N::translate('#%s', $faq->block_order + 1), ' ';
			if ($faq->gedcom_id === null) {
				echo I18N::translate('All');
			} else {
				echo $WT_TREE->getTitleHtml();
			}
			echo '</td>';
			// NOTE: Print the edit options of the current item
			echo '<td>';
			if ($faq->block_order == $min_block_order) {
				echo '&nbsp;';
			} else {
				echo '<a href="module.php?mod=', $this->getName(), '&amp;mod_action=admin_moveup&amp;block_id=', $faq->block_id, '"><i class="fa fa-arrow-up"></i></i> ', I18N::translate('Move up'), '</a>';
			}
			echo '</td><td>';
			if ($faq->block_order == $max_block_order) {
				echo '&nbsp;';
			} else {
				echo '<a href="module.php?mod=', $this->getName(), '&amp;mod_action=admin_movedown&amp;block_id=', $faq->block_id, '"><i class="fa fa-arrow-down"></i></i> ', I18N::translate('Move down'), '</a>';
			}
			echo '</td><td>';
			echo '<a href="module.php?mod=', $this->getName(), '&amp;mod_action=admin_edit&amp;block_id=', $faq->block_id, '"><i class="fa fa-pencil"></i> ', I18N::translate('Edit'), '</a>';
			echo '</td><td>';
			echo '<a href="module.php?mod=', $this->getName(), '&amp;mod_action=admin_delete&amp;block_id=', $faq->block_id, '" onclick="return confirm(\'', I18N::translate('Are you sure you want to delete “%s”?', Html::escape($faq->header)), '\');"><i class="fa fa-trash"></i> ', I18N::translate('Delete'), '</a>';
			echo '</td></tr>';
			// NOTE: Print the title text of the current item
			echo '<tr><td colspan="5">';
			echo '<div class="faq_edit_item">';
			echo '<div class="faq_edit_title">', $faq->header, '</div>';
			// NOTE: Print the body text of the current item
			echo '<div class="faq_edit_content">', substr($faq->faqbody, 0, 1) == '<' ? $faq->faqbody : nl2br($faq->faqbody, false), '</div></div></td></tr>';
		}
		echo '</table>';
	}

	/**
	 * The user can re-order menus. Until they do, they are shown in this order.
	 *
	 * @return int
	 */
	public function defaultMenuOrder() {
		return 40;
	}

	/**
	 * A menu, to be added to the main application menu.
	 *
	 * @return Menu|null
	 */
	public function getMenu() {
		global $WT_TREE;

		$faqs = Database::prepare(
			"SELECT block_id FROM `##block`" .
			" JOIN `##block_setting` USING (block_id)" .
			" WHERE module_name = :module_name AND IFNULL(gedcom_id, :tree_id_1) = :tree_id_2" .
			" AND setting_name='languages' AND (setting_value LIKE CONCAT('%', :locale, '%') OR setting_value='')"
		)->execute([
			'module_name' => $this->getName(),
			'tree_id_1'   => $WT_TREE->getTreeId(),
			'tree_id_2'   => $WT_TREE->getTreeId(),
			'locale'      => WT_LOCALE,
		])->fetchAll();

		if ($faqs) {
			return new Menu($this->getTitle(), 'module.php?mod=faq&amp;mod_action=show', 'menu-help');
		} else {
			return null;
		}
	}
}
