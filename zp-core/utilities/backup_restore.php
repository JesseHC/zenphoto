<?php
/**
 * Backup and restore of the ZenPhoto database table content
 *
 * This plugin provides a means to make backups of your ZenPhoto database content and
 * at a later time restore the database to the contents of one of these backups.
 *
 * @package zpcore\admin\utilities
 */
if (!defined('OFFSET_PATH'))
	define('OFFSET_PATH', 3);
define('HEADER', '__HEADER__');
define('RECORD_SEPARATOR', ':****:');
define('TABLE_SEPARATOR', '::');
define('RESPOND_COUNTER', 1000);

require_once(dirname(dirname(__FILE__)) . '/admin-globals.php');
require_once(dirname(dirname(__FILE__)) . '/template-functions.php');
$signaure = getOption('zenphoto_install');

$buttonlist[] = array(
		'category' => gettext('Admin'),
		'enable' => true,
		'button_text' => gettext('Backup/Restore'),
		'formname' => FULLWEBPATH . '/' . ZENFOLDER . '/' . UTILITIES_FOLDER . '/backup_restore.php',
		'action' => FULLWEBPATH . '/' . ZENFOLDER . '/' . UTILITIES_FOLDER . '/backup_restore.php',
		'icon' => FULLWEBPATH . '/' . ZENFOLDER . '/images/folder.png',
		'title' => gettext('Backup and restore your gallery database.'),
		'alt' => '',
		'hidden' => '',
		'rights' => ADMIN_RIGHTS
);

if (!$_zp_current_admin_obj || $_zp_current_admin_obj->getID()) {
	$rights = NULL;
} else {
	$rights = USER_RIGHTS;
}
admin_securityChecks($rights, currentRelativeURL());

if (isset($_REQUEST['backup']) || isset($_REQUEST['restore'])) {
	XSRFDefender('backup');
}

global $handle, $buffer, $counter, $file_version, $compression_handler; // so this script can run from a function
$buffer = '';

function extendExecution() {
	@set_time_limit(30);
	echo ' ';
}

function fillbuffer($handle) {
	global $buffer;
	$record = fread($handle, 8192);
	if ($record === false || empty($record)) {
		return false;
	}
	$buffer .= $record;
	return true;
}

function getrow($handle) {
	global $buffer, $counter, $file_version;
	if ($file_version == 0 || substr($buffer, 0, strlen(HEADER)) == HEADER) {
		$end = strpos($buffer, RECORD_SEPARATOR);
		while ($end === false) {
			if ($end = fillbuffer($handle)) {
				$end = strpos($buffer, RECORD_SEPARATOR);
			} else {
				return false;
			}
		}
		$result = substr($buffer, 0, $end);
		$buffer = substr($buffer, $end + strlen(RECORD_SEPARATOR));
	} else {
		$i = strpos($buffer, ':');
		if ($i === false) {
			fillbuffer($handle);
			$i = strpos($buffer, ':');
		}
		$end = substr($buffer, 0, $i) + $i + 1;
		while ($end >= strlen($buffer)) {
			if (!fillbuffer($handle))
				return false;
		}
		$result = substr($buffer, $i + 1, $end - $i - 1);
		$buffer = substr($buffer, $end);
	}
	return $result;
}

function decompressField($str) {
	global $compression_handler;
	switch ($compression_handler) {
		default:
			return $str;
		case 'bzip2':
			return bzdecompress($str);
		case 'gzip':
			return gzuncompress($str);
	}
}

function compressRow($str, $lvl) {
	global $compression_handler;
	switch ($compression_handler) {
		default:
			return $str;
		case 'bzip2_row':
			return bzcompress($str, $lvl);
		case 'gzip_row':
			return gzcompress($str, $lvl);
	}
}

function decompressRow($str) {
	global $compression_handler;
	switch ($compression_handler) {
		default:
			return $str;
		case 'bzip2_row':
			return bzdecompress($str);
		case 'gzip_row':
			return gzuncompress($str);
	}
}

function writeHeader($type, $value) {
	global $handle;
	return fwrite($handle, HEADER . $type . '=' . $value . RECORD_SEPARATOR);
}

if ($_zp_current_admin_obj->reset) {
	printAdminHeader('restore');
} else {
	$_zp_admin_menu['overview']['subtabs'] = array(gettext('Backup') => FULLWEBPATH . '/' . ZENFOLDER . '/' . UTILITIES_FOLDER . '/backup_restore.php');
	printAdminHeader('overview', 'backup');
}

echo '</head>';

$messages = '';

$prefix = $_zp_db->getPrefix();
$prefixLen = strlen($prefix);

if (isset($_REQUEST['backup'])) {
	$compression_level = sanitize($_REQUEST['compress'], 3);
	setOption('backup_compression', $compression_level);
	if ($compression_level > 0) {
		if (function_exists('bzcompress')) {
			$compression_handler = 'bzip2_row';
		} else {
			$compression_handler = 'gzip_row';
		}
	} else {
		$compression_handler = 'no';
	}
	$tables = $_zp_db->getTables();
	if (!empty($tables)) {
		$folder = getBackupFolder(SERVERPATH);
		$randomkey = bin2hex(random_bytes(5));
		$filename = $folder . 'backup-' . date('Y_m_d-H_i_s') . '_' . $randomkey . '.zdb';
		if (!is_dir($folder)) {
			mkdir($folder, FOLDER_MOD);
		}
		@chmod($folder, FOLDER_MOD);
		$writeresult = $handle = @fopen($filename, 'w');
		if ($handle === false) {
			$msg = sprintf(gettext('Failed to open %s for writing.'), $filename);
			echo $msg;
		} else {
			$writeresult = writeheader('file_version', 1);
			$writeresult = $writeresult && writeHeader('compression_handler', $compression_handler);
			if ($writeresult === false) {
				$msg = gettext('failed writing to backup!');
			}

			$counter = 0;
			$writeresult = true;
			foreach ($tables as $table) {
				$unprefixed_table = substr($table, strlen($prefix));
				$sql = 'SELECT * from `' . $table . '`';
				$result = $_zp_db->query($sql);
				if ($result) {
					while ($tablerow = $_zp_db->fetchAssoc($result)) {
						extendExecution();
						$storestring = serialize($tablerow);
						$storestring = compressRow($storestring, $compression_level);
						$storestring = $unprefixed_table . TABLE_SEPARATOR . $storestring;
						$storestring = strlen($storestring) . ':' . $storestring;
						$writeresult = fwrite($handle, $storestring);
						if ($writeresult === false) {
							$msg = gettext('failed writing to backup!');
							break;
						}
						$counter++;
						if ($counter >= RESPOND_COUNTER) {
							echo ' ';
							$counter = 0;
						}
					}
					$_zp_db->freeResult($result);
				}
				if ($writeresult === false)
					break;
			}
			fclose($handle);
			@chmod($filename, 0660 & CHMOD_VALUE);
		}
	} else {
		$msg = gettext('SHOW TABLES failed!');
		$writeresult = false;
	}
	if ($writeresult) {
		if (isset($_REQUEST['autobackup'])) {
			setOption('last_backup_run', time());
		}
		$messages = '
		<div class="messagebox fade-message">
		<h2>
		';
		if ($compression_level > 0) {
			$messages .= sprintf(gettext('backup completed using <em>%1$s(%2$s)</em> compression'), $compression_handler, $compression_level);
		} else {
			$messages .= gettext('backup completed');
		}
		$messages .= '
		</h2>
		</div>
		<?php
		';
	} else {
		if (isset($_REQUEST['autobackup'])) {
			debugLog(sprintf('Autobackup failed: %s', $msg));
		}
		$messages = '
		<div class="errorbox fade-message">
		<h2>' . gettext("backup failed") . '</h2>
		<p>' . $msg . '</p>
		</div>
		';
	}
} else if (isset($_REQUEST['restore'])) {
	$oldlibauth = Authority::getVersion();
	$errors = array(gettext('No backup set found.'));
	if (isset($_REQUEST['backupfile'])) {
		$file_version = 0;
		$compression_handler = 'gzip';
		$folder = getBackupFolder(SERVERPATH);
		$filename = $folder . internalToFilesystem(sanitize($_REQUEST['backupfile'], 3)) . '.zdb';
		if (file_exists($filename)) {
			$handle = fopen($filename, 'r');
			if ($handle !== false) {
				$alltables = $_zp_db->getTables();
				$unique = $tables = array();
				$table_cleared = array();
				if ($alltables) {
					foreach ($alltables as $table) {
						extendExecution();
						$tables[$table] = array();
						$table_cleared[$table] = false;
						$result2 = $_zp_db->getFields(substr($table, $prefixLen));
						if (is_array($result2)) {
							foreach ($result2 as $row) {
								$tables[$table][] = $row['Field'];
							}
						}
						$result2 = $_zp_db->show('index', $table);
						if (is_array($result2)) {
							foreach ($result2 as $row) {
								if (is_array($row)) {
									if (array_key_exists('Non_unique', $row) && !$row['Non_unique']) {
										$unique[$table][] = $row['Column_name'];
									}
								}
							}
						}
					}
				}

				$errors = array();
				$string = getrow($handle);
				while (substr($string, 0, strlen(HEADER)) == HEADER) {
					$string = substr($string, strlen(HEADER));
					$i = strpos($string, '=');
					$type = substr($string, 0, $i);
					$what = substr($string, $i + 1);
					switch ($type) {
						case 'compression_handler':
							$compression_handler = $what;
							break;
						case 'file_version':
							$file_version = $what;
					}
					$string = getrow($handle);
				}
				$counter = 0;
				$missing_table = array();
				$missing_element = array();
				while (!empty($string) && count($errors) < 100) {
					extendExecution();
					$sep = strpos($string, TABLE_SEPARATOR);
					$table = substr($string, 0, $sep);
					if (array_key_exists($prefix . $table, $tables)) {
						if (!$table_cleared[$prefix . $table]) {
							if (!$_zp_db->truncateTable($table)) {
								$errors[] = gettext('Truncate table<br />') . $_zp_db->getError();
							}
							$table_cleared[$prefix . $table] = true;
						}
						$row = substr($string, $sep + strlen(TABLE_SEPARATOR));
						$row = decompressRow($row);
						$row = unserialize($row);


						foreach ($row as $key => $element) {
							if ($compression_handler == 'bzip2' || $compression_handler == 'gzip') {
								if (!empty($element)) {
									$element = decompressField($element);
								}
							}
							if (array_search($key, $tables[$prefix . $table]) === false) {
								//	Flag it if data will be lost
								$missing_element[] = $table . '->' . $key;
								unset($row[$key]);
							} else {
								if (is_null($element)) {
									$row[$key] = 'NULL';
								} else {
									$row[$key] = $_zp_db->quote($element);
								}
							}
						}
						if (!empty($row)) {
							if ($table == 'options') {
								if ($row['name'] == 'zenphoto_install') {
									break;
								}
								if ($row['theme'] == 'NULL') {
									$row['theme'] = $_zp_db->quote('');
								}
							}
							$sql = 'INSERT INTO ' . $_zp_db->prefix($table) . ' (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $row) . ')';
							foreach ($unique[$prefix . $table] as $exclude) {
								unset($row[$exclude]);
							}
							if (count($row) > 0) {
								$sqlu = ' ON DUPLICATE KEY UPDATE ';
								foreach ($row as $key => $value) {
									$sqlu .= '`' . $key . '`=' . $value . ',';
								}
								$sqlu = substr($sqlu, 0, -1);
							} else {
								$sqlu = '';
							}
							if (!$_zp_db->query($sql . $sqlu, false)) {
								$errors[] = $sql . $sqlu . '<br />' . $_zp_db->getError();
							}
						}
					} else {
						$missing_table[] = $table;
					}
					$counter++;
					if ($counter >= RESPOND_COUNTER) {
						echo ' ';
						$counter = 0;
					}
					$string = getrow($handle);
				}
			}
			fclose($handle);
		}
	}
	if (!empty($missing_table) || !empty($missing_element)) {
		$messages = '
		<div class="warningbox">
			<h2>' . gettext("Restore encountered exceptions") . '</h2>';
		if (!empty($missing_table)) {
			$messages .= '
				<p>' . gettext('The following tables were not restored because the table no longer exists:') . '
					<ul>
					';
			foreach (array_unique($missing_table) as $item) {
				$messages .= '<li><em>' . $item . '</em></li>';
			}
			$messages .= '
					</ul>
				</p>
				';
		}
		if (!empty($missing_element)) {
			$messages .= '
				<p>' . gettext('The following fields were not restored because the field no longer exists:') . '
					<ul>
					';

			foreach (array_unique($missing_element) as $item) {
				$messages .= '<li><em>' . $item . '</em></li>';
			}
			$messages .= '
					</ul>
				</p>
				';
		}
		$messages .= '
		</div>
		';
	} else if (count($errors) > 0) {
		$messages = '
		<div class="errorbox">
			<h2>';
		if (count($errors) >= 100) {
			$messages .= gettext('The maximum error count was exceeded and the restore aborted.');
			unset($_GET['compression']);
		} else {
			$messages .= gettext("Restore encountered the following errors:");
		}
		$messages .= '</h2>
			';
		foreach ($errors as $msg) {
			$messages .= '<p>' . html_encode($msg) . '</p>';
		}
		$messages .= '
		</div>
		';
	} else {
		$messages = '
			<script>
				window.onload = function() {
					window.location = "' . FULLWEBPATH . '/' . ZENFOLDER . '/' . UTILITIES_FOLDER . '/backup_restore.php?compression=' . $compression_handler . '";
				}
			</script>
		';
	}
	$_zp_options = NULL; //invalidate any options from before the restore
	if (getOption('zenphoto_install') !== $signaure) {
		$l1 = '<a href="' . WEBPATH . '/' . ZENFOLDER . '/setup.php">';
		$messages .= '<div class="notebox">
			<h2>' . sprintf(gettext('You have restored your database content from a different instance of Zenphoto. You should run %1$ssetup%2$s to insure proper migration.'), $l1, '</a>') . '</h2>
			</div>';
	}

	setOption('license_accepted', ZENPHOTO_VERSION);
	if ($oldlibauth != Authority::getVersion()) {
		if (!$_zp_authority->migrateAuth($oldlibauth)) {
			$messages .= '
			<div class="errorbox fade-message">
			<h2>' . gettext('Zenphoto Rights migration failed!') . '</h2>
			</div>
			';
		}
	}
}

if (isset($_GET['compression'])) {
	$compression_handler = sanitize($_GET['compression']);
	$messages = '
	<div class="messagebox fade-message">
		<h2>
			';
	if ($compression_handler == 'no') {
		$messages .= (gettext('Restore completed'));
	} else {
		$messages .= sprintf(gettext('Restore completed using %s compression'), html_encode($compression_handler));
	}
	$messages .= '
		</h2>
	</div>
	';
}
?>

<body>
	<?php printLogoAndLinks(); ?>
	<div id="main">
		<?php printTabs(); ?>
		<div id="content">
			<?php
			if (!$_zp_current_admin_obj->reset) {
				printSubtabs();
			}
			?>
			<div class="tabbox">
				<?php zp_apply_filter('admin_note', 'backkup', ''); ?>
				<h1>
					<?php
					if ($_zp_current_admin_obj->reset) {
						echo (gettext('Restore your database content'));
					} else {
						echo (gettext('Backup and Restore your database content'));
					}
					?>
				</h1>
				<?php
				echo $messages;
				$compression_level = getOption('backup_compression');
				?>
				<p>
					<?php printf(gettext("Database software <strong>%s</strong>"), DATABASE_SOFTWARE); ?><br />
					<?php printf(gettext("Database name <strong>%s</strong>"), $_zp_db->getDBName()); ?><br />
					<?php printf(gettext("Tables prefix <strong>%s</strong>"), $_zp_db->getPrefix()); ?>
				</p>
				<?php
				if (!$_zp_current_admin_obj->reset) {
					echo '<p>';
					echo gettext('The backup facility creates database content snapshots in the <code>backup</code> folder of your installation. These backups are named in according to the date and time the backup was taken.' .
									'The compression level goes from 0 (no compression) to 9 (maximum compression). Higher compression requires more processing and may not result in much space savings.');
					echo '</p>';
				}
				if (!$_zp_current_admin_obj->reset) {
					?>
					<hr>
					<form name="backup_gallery" action="">
						<?php XSRFToken('backup'); ?>
						<h2><?php echo gettext('Create backup'); ?></h2>
						<input type="hidden" name="backup" value="true" />
						<div class="buttons pad_button" id="dbbackup">
							<button class="fixedwidth tooltip" type="submit" title="<?php echo gettext("Backup the table content in your database."); ?>">
								<img src="<?php echo WEBPATH . '/' . ZENFOLDER; ?>/images/burst.png" alt="" /> <?php echo gettext("Backup the Database"); ?>
							</button>
							<select name="compress">
								<?php
								for ($v = 0; $v <= 9; $v++) {
									?>
									<option value="<?php echo $v; ?>"<?php if ($compression_level == $v) echo ' selected="selected"'; ?>><?php echo $v; ?></option>
									<?php
								}
								?>
							</select> <?php echo gettext('Compression level'); ?>
						</div>

					</form>
					<br />
					<?php
				}
				$filelist = safe_glob(getBackupFolder(SERVERPATH) . '*.zdb');
				if (count($filelist) <= 0) {
					echo gettext('You have not yet created a backup set.');
				} else {
					?>
					<hr>
					<h2><?php echo gettext('Backup restore'); ?></h2>
					<?php
					echo gettext('You restore your database content by selecting a backup and pressing the <em>Restore the Database</em> button.');
					echo '</p><p class="warningbox">' . gettext('<strong>Note:</strong> Each database table is emptied before the restore is attempted. After a successful restore the database content will be in the same state as when the backup was created.');
					echo '</p><p class="notebox">';
					echo gettext('Ideally a restore should be done only on the same version of Zenphoto on which the backup was created. If you are intending to upgrade, first do the restore on the version of Zenphoto you were running, then install the new Zenphoto. If this is not possible the restore can still be done, but if the database fields have changed between versions, data from changed fields will not be restored.');
					echo '</p>';
					?>
					<form name="restore_gallery" action="">
						<?php XSRFToken('backup'); ?>

						<?php echo gettext('Select the database restore file:'); ?>
						<br />
						<select id="backupfile" name="backupfile">
							<?php generateListFromFiles('', getBackupFolder(SERVERPATH), '.zdb', true); ?>
						</select>
						<input type="hidden" name="restore" value="true" />
						<script>
							$(document).ready(function () {
								$("#restore_button").click(function () {
									if (!confirm('<?php echo gettext('Do you really want to restore the database content? Restoring the wrong backup might result in data loss!'); ?>')) {
										return false;
									}
									;
								});
							});
						</script>
						<div class="buttons pad_button" id="dbrestore">
							<button id="restore_button" class="fixedwidth tooltip" type="submit" title="<?php echo gettext("Restore the table content in your database from a previous backup."); ?>">
								<img src="<?php echo WEBPATH . '/' . ZENFOLDER; ?>/images/redo.png" alt="" /> <?php echo gettext("Restore the Database"); ?>
							</button>
						</div>
						<br class="clearall" />
						<br class="clearall" />
					</form>
					<?php
				}
				?>
			</div>
		</div><!-- content -->
	</div><!-- main -->
	<?php printAdminFooter(); ?>
</body>
<?php echo "</html>"; ?>
