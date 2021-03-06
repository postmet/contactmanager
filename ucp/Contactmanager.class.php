<?php
/**
 * This is the User Control Panel Object.
 *
 * Copyright (C) 2014 Schmooze Com, INC
 */
namespace UCP\Modules;
use \UCP\Modules as Modules;

class Contactmanager extends Modules{
	protected $module = 'Contactmanager';
	private $ext = 0;

	public function __construct($Modules) {
		$this->Modules = $Modules;
		$this->cm = $this->UCP->FreePBX->Contactmanager;
		$this->user = $this->UCP->User->getUser();
	}

	/**
	* Determine what commands are allowed
	*
	* Used by Ajax Class to determine what commands are allowed by this class
	*
	* @param string $command The command something is trying to perform
	* @param string $settings The Settings being passed through $_POST or $_PUT
	* @return bool True if pass
	*/
	function ajaxRequest($command, $settings) {
		switch($command) {
			case 'updatecontact':
			case 'deletecontact':
			case 'addcontact':
			case 'deletegroup':
			case 'addgroup':
			case 'grid':
			case 'limage':
			case 'uploadimage':
			case 'delimage':
			case 'getgravatar':
				return true;
			default:
				return false;
			break;
		}
	}

	public function userDetails() {
		$cmdata = $this->cm->getEntryIDByUsermanID($this->user['id']);
		return $cmdata;
	}

	public function ajaxCustomHandler() {
		switch($_REQUEST['command']) {
			case "limage":
				if(!empty($_REQUEST['temporary'])) {
					$id = null;
				} elseif(empty($_REQUEST['entryid'])) {
					$entry = $this->userDetails();
					$id = $entry['id'];
				} else {
					if(!$this->editEntry($_REQUEST['entryid'])) {
						return false;
					}
					$id = $_REQUEST['entryid'];
				}
				$this->cm->displayContactImage($id);
				return true;
			break;
		}
	}

	/**
	* The Handler for all ajax events releated to this class
	*
	* Used by Ajax Class to process commands
	*
	* @return mixed Output if success, otherwise false will generate a 500 error serverside
	*/
	function ajaxHandler() {
		$return = array("status" => false, "message" => "");
		switch($_REQUEST['command']) {
			case 'getgravatar':
				$type = !empty($_POST['grouptype']) ? $_POST['grouptype'] : "";
				$id = $this->user['id'];
				switch($type) {
					case "external":
						$email = !empty($_POST['email']) ? $_POST['email'] : '';
					break;
					case "internal":
						$data = $this->UCP->FreePBX->Userman->getUserByID($id);
						$email = $data['email'];
					break;
					case "userman":
						$email = !empty($_POST['email']) ? $_POST['email'] : '';
						if(empty($email)) {
							$data = $this->UCP->FreePBX->Userman->getUserByID($id);
							$email = $data['email'];
						}
					break;
				}
				if(empty($email)) {
					return array("status" => false, "message" => _("Please enter a valid email address"));
				}
				$data = $this->cm->getGravatar($email);
				if(!empty($data)) {
					$dname = "cm-".rand()."-".md5($email);
					imagepng(imagecreatefromstring($data), $this->cm->tmp."/".$dname.".png");
					if(!empty($_REQUEST['type']) && $_REQUEST['type'] == 'contact') {
						if(!empty($_REQUEST['id'])) {
							if(!$this->editEntry($_REQUEST['id'])) {
								return array("status" => false, "message" => _("Invalid"));
							}
							$this->cm->updateImageByEntryID($_REQUEST['id'], $this->cm->tmp."/".$dname.".png", true);
							$url = "?quietmode=1&module=Contactmanager&command=limage&entryid=".$_REQUEST['id']."&time=".time();
						} else {
							$url = "?quietmode=1&module=Contactmanager&command=limage&temporary=1&name=".$dname.".png";
						}
					} elseif(empty($_POST['type'])) {
						$entry = $this->cm->getEntryIDByUsermanID($this->user['id']);
						$this->cm->updateImageByEntryID($entry['id'], $this->cm->tmp."/".$dname.".png", true);
						$url = "?quietmode=1&module=Contactmanager&command=limage&time=".time();
					}
					return array("status" => true, "name" => $dname, "filename" => $dname.".png", "url" => $url);
				} else {
					return array("status" => false, "message" => sprintf(_("Unable to find gravatar for %s"),$email));
				}

			break;
			case "delimage":
				if(!empty($_POST['id'])) {
					if(!$this->editEntry($_POST['id'])) {
						return array("status" => false, "message" => _("Invalid"));
					}
					$this->cm->delImageByEntryID($_POST['id']);
					return array("status" => true);
				} elseif(!empty($_POST['image'])) {
					unlink($this->cm->tmp."/".$_POST['image'].".png");
					return array("status" => true);
				} else {
					$entry = $this->cm->getEntryIDByUsermanID($this->user['id']);
					if(!empty($entry)) {
						$this->cm->delImageByEntryID($entry['id']);
						return array("status" => true);
					}
				}
				return array("status" => false, "message" => _("Invalid"));
			break;
			case 'uploadimage':
				// XXX If the posted file was too large,
				// we will get here, but $_FILES is empty!
				// Specifically, if the file that was posted is
				// larger than 'post_max_size' in php.ini.
				// So, php will throw an error, as index
				// $_FILES["files"] does not exist, because
				// $_FILES is empty.
				if (!isset($_FILES)) {
					return array("status" => false,
						"message" => _("File upload failed"));
				}
				$this->UCP->FreePBX->Media();
				foreach ($_FILES["files"]["error"] as $key => $error) {
					switch($error) {
						case UPLOAD_ERR_OK:
							$extension = pathinfo($_FILES["files"]["name"][$key], PATHINFO_EXTENSION);
							$extension = strtolower($extension);
							$supported = array("jpg","png");
							if(in_array($extension,$supported)) {
								$tmp_name = $_FILES["files"]["tmp_name"][$key];
								$dname = \Media\Media::cleanFileName($_FILES["files"]["name"][$key]);
								$dname = "cm-".rand()."-".pathinfo($dname,PATHINFO_FILENAME);
								$this->cm->resizeImage(file_get_contents($tmp_name),$dname);
								if(!empty($_REQUEST['type']) && $_REQUEST['type'] == 'contact') {
									if(!empty($_REQUEST['id'])) {
										$this->cm->updateImageByEntryID($_REQUEST['id'], $this->cm->tmp."/".$dname.".png", false);
										$url = "?quietmode=1&module=Contactmanager&command=limage&entryid=".$_REQUEST['id']."&time=".time();
									} else {
										$url = "?quietmode=1&module=Contactmanager&command=limage&temporary=1&name=".$dname.".png";
									}
								} elseif(empty($_POST['type'])) {
									$entry = $this->cm->getEntryIDByUsermanID($this->user['id']);
									$this->cm->updateImageByEntryID($entry['id'], $this->cm->tmp."/".$dname.".png", false);
									$url = "?quietmode=1&module=Contactmanager&command=limage&time=".time();
								}
								return array("status" => true, "name" => $dname, "filename" => $dname.".png", "url" => $url);
							} else {
								return array("status" => false, "message" => _("Unsupported file format"));
								break;
							}
						break;
						case UPLOAD_ERR_INI_SIZE:
							return array("status" => false, "message" => _("The uploaded file exceeds the upload_max_filesize directive in php.ini"));
						break;
						case UPLOAD_ERR_FORM_SIZE:
							return array("status" => false, "message" => _("The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form"));
						break;
						case UPLOAD_ERR_PARTIAL:
							return array("status" => false, "message" => _("The uploaded file was only partially uploaded"));
						break;
						case UPLOAD_ERR_NO_FILE:
							return array("status" => false, "message" => _("No file was uploaded"));
						break;
						case UPLOAD_ERR_NO_TMP_DIR:
							return array("status" => false, "message" => _("Missing a temporary folder"));
						break;
						case UPLOAD_ERR_CANT_WRITE:
							return array("status" => false, "message" => _("Failed to write file to disk"));
						break;
						case UPLOAD_ERR_EXTENSION:
							return array("status" => false, "message" => _("A PHP extension stopped the file upload"));
						break;
					}
				}
				return array("status" => false, "message" => _("Can Not Find Uploaded Files"));
			break;
			case 'grid':
				//$limit = $_REQUEST['limit'];
				$group = $_REQUEST['id'];
				$order = $_REQUEST['order'];
				$orderby = !empty($_REQUEST['sort']) ? $_REQUEST['sort'] : "displayname";
				$search = !empty($_REQUEST['search']) ? $_REQUEST['search'] : "";
				if(empty($group)) {
					$groups = $this->cm->getGroupsByOwner($this->user['id']);
					$allContacts = array();
					foreach($groups as $group) {
						$contacts = $this->cm->getEntriesByGroupID($group['id']);
						$allContacts = array_merge($allContacts,$contacts);
					}
					@usort($allContacts, function($a, $b) {
						return strnatcmp($a[$orderby], $b[$orderby]);
					});
					$contacts = array_values($allContacts);
				} else {
					$contacts = $this->cm->getEntriesByGroupID($group);
					$contacts = array_values($contacts);
				}
				if(!empty($search)) {
					$temp = $contacts;
					$contacts = array();
					foreach($temp as $c) {
						if($this->pregRecursiveArraySearch($search,$c, array('displayname','fname','lname','title','company')) !== false) {
							$contacts[] = $c;
						}
					}
				}
				if($order == 'asc') {
					$contacts = array_reverse($contacts);
				}
				return $contacts;
			break;
			case 'updatecontact':
				if(!$this->editEntry($_REQUEST['id'])) {
					$return = array("status" => false, "message" => _("Unauthorized"));
				}
				$entry = $this->cm->getEntryByID($_REQUEST['id']);
				if(!empty($entry)) {
					$entry[$_REQUEST['key']] = $_REQUEST['value'];
					$return = $this->cm->updateEntry($_REQUEST['id'], $entry);
					break;
				}
				$return = array("status" => false, "message" => _("Unauthorized"));
			break;
			case 'deletecontact':
				if(!$this->editEntry($_REQUEST['id'])) {
					$return = array("status" => false, "message" => _("Unauthorized"));
				}
				$entry = $this->cm->getEntryByID($_REQUEST['id']);
				if(!empty($entry)) {
					$g = $this->cm->getGroupByID($entry['groupid']);
					if($g['owner'] == $this->user['id']) {
						$return = $this->cm->deleteEntryByID($_REQUEST['id']);
						break;
					}
				}
				$return = array("status" => false, "message" => _("Unauthorized"));
			break;
			case 'addcontact':
				$g = $this->cm->getGroupByID($_REQUEST['id']);
				if($g['owner'] == $this->user['id']) {
					$contact = $_REQUEST['contact'];
					$contact['user'] = -1;
					$return = $this->cm->addEntryByGroupID($_REQUEST['id'], $contact);
				} else {
					$return = array("status" => false, "message" => _("Unauthorized"));
				}
			break;
			case 'deletegroup':
				$g = $this->cm->getGroupByID($_REQUEST['id']);
				if($g['owner'] == $this->user['id']) {
					$return = $this->cm->deleteGroupByID($_REQUEST['id']);
					$return['name'] = $g['name'];
				} else {
					$return = array("status" => false, "message" => _("Unauthorized"));
				}
			break;
			case 'addgroup':
				$return = $this->cm->addGroup($_POST['name'], 'external', $this->user['id']);
			break;
			default:
				return false;
			break;
		}
		return $return;
	}

	function pregRecursiveArraySearch($needle,$haystack,$validKeys=array()) {
		foreach($haystack as $key=>$value) {
			if(!empty($validKeys) && !in_array($key, $validKeys)) {
				continue;
			}
			$current_key = $key;
			if(preg_match('/'.$needle.'/i',$value) OR (is_array($value) && $this->pregRecursiveArraySearch($needle,$value) !== false)) {
				return $current_key;
			}
		}
		return false;
	}

	/**
	* Generate the display in UCP
	*/
	public function getDisplay() {
		$view = !empty($_REQUEST['view']) ? $_REQUEST['view'] : '';

		$displayvars = array();
		$displayvars['groups'] = $this->cm->getGroupsByOwner($this->user['id']);
		$displayvars['activeList'] = "mycontacts";
		$displayvars['total'] = 0;
		$c = 1;
		foreach($displayvars['groups'] as &$group) {
			$group['readonly'] = ($group['owner'] == -1);
			$group['contacts'] = $this->cm->getEntriesByGroupID($group['id']);
			$group['count'] = count($group['contacts']);
			$displayvars['total'] = $displayvars['total'] + $group['count'];
			if(!empty($_REQUEST['view']) && $_REQUEST['view'] == "group" && $_REQUEST['id'] == $group['id']) {
				$displayvars['activeList'] = $group['name'];
				$displayvars['readonly'] = $group['readonly'];
			}else if(!empty($_REQUEST['view']) && $_REQUEST['view'] == "contact" && $_REQUEST['group'] == $group['id']) {
				$displayvars['activeList'] = $group['name'];
				$displayvars['readonly'] = $group['readonly'];
			}
		}

		switch($view) {
			case "addcontact":
				$g = $this->cm->getGroupByID($_REQUEST['group']);
				if(!empty($g)) {
					if($g['owner'] != -1) {
						$displayvars['activeList'] = $g['name'];
						$displayvars['add'] = true;
						$mainDisplay = $this->load_view(__DIR__.'/views/contact.php',$displayvars);
						break;
					}
				}
				$displayvars['activeList'] = '';
				$mainDisplay = _("Not Authorized");
			break;
			case "addgroup":
				$displayvars['activeList'] = "addgroup";
				$mainDisplay = $this->load_view(__DIR__.'/views/groupcreate.php',$displayvars);
			break;
			case "contact":
				$g = $this->cm->getGroupByID($_REQUEST['group']);
				if(!empty($g)) {
					$displayvars['contact'] = $this->cm->getEntryByID($_REQUEST['id']);
					if($g['owner'] == -1) {
						$mainDisplay = $this->load_view(__DIR__.'/views/contactro.php',$displayvars);
					} else {
						if(!empty($_REQUEST['mode']) && $_REQUEST['mode'] == 'edit') {
							$mainDisplay = $this->load_view(__DIR__.'/views/contact.php',$displayvars);
						} else {
							$displayvars['editable'] = true;
							$mainDisplay = $this->load_view(__DIR__.'/views/contactro.php',$displayvars);
						}
					}
				} else {
					$mainDisplay = _("Not Authorized");
				}
			break;
			default:
				$mainDisplay = $this->load_view(__DIR__.'/views/contacts.php',$displayvars);
			break;
		}

		$html = $this->load_view(__DIR__.'/views/nav.php',$displayvars);
		$html .= $mainDisplay;
		return $html;
	}

	public function lookupMultiple($search) {
		$entry = $this->cm->lookupMultipleByUserID($this->user['id'],$search);
		return $entry;
	}

	public function lookup($search) {
		$entry = $this->cm->lookupByUserID($this->user['id'],$search);
		return $entry;
	}

	/**
	* Setup Menu Items for display in UCP
	*/
	public function getMenuItems() {
		$menu = array(
			"rawname" => "contactmanager",
			"name" => _("Contacts")
		);
		return $menu;
	}

	public function poll() {
		$contacts = $this->cm->getContactsByUserID($this->user['id']);
		if(!empty($contacts)) {
			return array(
				'enabled' => true,
				'contacts' => $contacts
			);
		} else {
			return array('enabled' => false);
		}
	}

	/**
	* Send settings to UCP upon initalization
	*/
	public function getStaticSettings() {
		$contacts = $this->cm->getContactsByUserID($this->user['id']);
		if(!empty($contacts)) {
			return array(
				'enabled' => true,
				'contacts' => $contacts
			);
		} else {
			return array('enabled' => false);
		}
	}

	public function editEntry($id) {
		$contacts = $this->cm->getContactsByUserID($this->user['id']);
		$allowed = false;
		foreach($contacts as $contact) {
			if($contact['uid'] == $id) {
				return true;
			}
		}
		return false;
	}
}
