<?php

namespace App\Http\Controllers;

use DB;
use Auth;
use File;
use Session;
use Mail;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

class FormController extends Controller
{
	// define modules to create, update or delete user when module is saved
	public static $user_via_modules = [];
	public static $generate_no_modules = [];
	public static $email_modules = [];
	public static $link_field_value;


	// Shows the form view for the record 
	public static function show($form_config) {
		$user_role = self::get_from_session('role');

		if ($user_role == 'Administrator') {
			return self::show_form($form_config);
		}
		else {
			$allowed = PermController::role_wise_modules($user_role, "Read", $form_config['module']);
			if ($allowed) {
				return self::show_form($form_config);
			}
			else {
				self::put_to_session('success', "false");
				return back()->withInput()->with(['msg' => 'You are not authorized to view "'. $form_config['module_label'] . '" record(s)']);
			}
		}
	}


	// Shows form view
	public static function show_form($form_config) {
		// Shows an existing record
		if ($form_config['link_field_value']) {
			$owner = self::get_from_session('login_id');
			$data[$form_config['table_name']] = DB::table($form_config['table_name'])->where($form_config['link_field'], $form_config['link_field_value'])->first();

			if ($data && $data[$form_config['table_name']]) {
				// if child tables set and found in db then attach it with data
				if(isset($form_config['child_tables']) && isset($form_config['child_foreign_key'])) {
					foreach ($form_config['child_tables'] as $child_table) {
						$data[$child_table] = DB::table($child_table)->where($form_config['child_foreign_key'], $form_config['link_field_value'])->get();
					}
				}
			}
			else {
				self::put_to_session('success', "false");
				abort('404');
			}
		}
		// Shows a new form
		else {
			$user_role = self::get_from_session('role');
		}

		$form_data = [
			'data' => isset($data) ? $data : [],
			'link_field' => $form_config['link_field'],
			'record_identifier' => isset($form_config['record_identifier']) ? $form_config['record_identifier'] : $form_config['link_field'],
			'title' => $form_config['module_label'],
			'icon' => $form_config['module_icon'],
			'file' => $form_config['view'],
			'module' => $form_config['module']
		];

		return view('templates.form_view', $form_data);
	}


	// Saves or Updates the record to the database
	public static function save($request, $form_config) {
		$user_role = self::get_from_session('role');
		$record_exists = self::check_existing($request, $form_config);

		if ($user_role == 'Administrator') {
			// Updates an existing database
			if ($form_config['link_field_value'] && $record_exists) {
				$result = self::save_form($request, $form_config, "update");
			}
			// Inserts a new record to the database
			else {
				$result = self::save_form($request, $form_config, "create", $record_exists);
			}
		}
		else {
			$allow_create = PermController::role_wise_modules($user_role, "Create", $form_config['module']);
			$allow_update = PermController::role_wise_modules($user_role, "Update", $form_config['module']);

			if ($form_config['link_field_value']) {
				if ($allow_update && $record_exists) {
					$result = self::save_form($request, $form_config, "update");
				}
				else {
					self::put_to_session('success', "false");
					return back()->withInput()->with(['msg' => 'You are not authorized to update "'. $form_config['module_label'] . '" record(s)']);
				}
			}
			else {
				if ($allow_create) {
					$result = self::save_form($request, $form_config, "create", $record_exists);
				}
				else {
					self::put_to_session('success', "false");
					return back()->withInput()->with(['msg' => 'You are not authorized to create "'. $form_config['module_label'] . '" record(s)']);
				}
			}
		}

		if ($result && self::get_from_session('success') == "true") {
			$form_config['link_field_value'] = self::$link_field_value;
			return redirect($form_config['form_view'].$form_config['link_field_value'])
				->with(['msg' => $form_config['module_label'] . ': "' . $form_config['link_field_value'] . '" saved successfully']);
		}
		else {
			return $result;
		}
	}


	// Saves record in database
	public static function save_form($request, $form_config, $action, $record_exists = null) {
		// if record already exists in database while creating
		if ($action == "create" && isset($record_exists) && $record_exists) {
			self::put_to_session('success', "false");
			return redirect($form_config['form_view'])
				->with(['msg' => $form_config['module_label'] . ': "' . $request->$form_config['link_field'] . '" already exist']);
		}
		// if link field value is not matching the request link value
		elseif ($action == "update" && $request->$form_config['link_field'] != $form_config['link_field_value']) {
			self::put_to_session('success', "false");
			return redirect($form_config['form_view'].$form_config['link_field_value'])
				->with(['msg' => 'You cannot change "' . $form_config['link_field_label'] . '" for ' . $form_config['module_label']]);
		}
		else {
			$form_data = self::populate_data($request, $form_config, $action);
			$result = self::save_data_into_db($form_data, $form_config, $action);
		}

		// if data is inserted into database then only save avatar, user, etc.
		if ($result) {
			self::put_to_session('success', "true");

			// increase document no counter
			if (in_array($form_config['module'], array_keys(self::$generate_no_modules))) {
				DB::table('tabDocumentNo')
					->where('document_name', $form_config['module'])
					->increment('last_document_no');
			}

			$data = $form_data[$form_config['table_name']];
			if (isset($data['avatar']) && $data['avatar']) {
				$avatar = $request->file('avatar');
				$folder_path = $form_config['avatar_folder'] ? $form_config['avatar_folder'] : '/images';

				$avatar->move(public_path().$folder_path, $data['avatar']);
			}

			// create user if modules come under user_via_modules
			if (in_array($form_config['module'], self::$user_via_modules) && $result) {
				self::user_form_action($request, $form_config['module'], $action, isset($data['avatar']) ? $data['avatar'] : "");
			}

			// send email if come in email modules
			if (in_array($form_config['module'], self::$email_modules) && $result) {
				if (SettingsController::get_app_setting('email') == "Active") {
					EmailController::send(null, $data['guest_id'], null, $data, $form_config['module']);
				}
			}

			return $result;
		}
		else {
			self::put_to_session('success', "false");
			return redirect($form_config['form_view'].$form_config['link_field_value'])
				->with(['msg' => 'Oops! Some problem occured. Please try again']);
		}
	}


	// insert or updates records into the database
	public static function save_data_into_db($form_data, $form_config, $action) {
		DB::enableQueryLog();
		// save parent data and child table data if found
		foreach ($form_data as $form_table => $form_table_data) {

			if ($form_table == $form_config['table_name']) {
				// this is parent table
				if ($action == "create") {
					$result = DB::table($form_table)->insertGetId($form_table_data);
					self::put_to_session("created_id", $result);
					$form_config['link_field_value'] = ($form_config['link_field'] == "id") ? $result : $form_table_data[$form_config['link_field']];
				}
				else {
					$result = DB::table($form_table)->where($form_config['link_field'], $form_config['link_field_value'])
						->update($form_table_data);
				}

				self::$link_field_value = $form_config['link_field_value'];
			}
			else {
				foreach ($form_table_data as $child_record) {
					if ($action == "create") {
						unset($child_record['action']);
						if (!isset($child_record[$form_config['child_foreign_key']])) {
							$child_record[$form_config['child_foreign_key']] = $form_config['link_field_value'];
						}
						$result = DB::table($form_table)->insert($child_record);
					}
					else {
						if ($child_record['action'] == "create") {
							unset($child_record['action']);
							$child_record['owner'] = self::get_from_session('login_id');
							$child_record['created_at'] = date('Y-m-d H:i:s');

							$result = DB::table($form_table)->insert($child_record);
						}
						elseif ($child_record['action'] == "update") {
							unset($child_record['action']);
							$id = $child_record['id'];
							unset($child_record['id']);

							$result = DB::table($form_table)->where('id', $id)->update($child_record);
						}
						elseif ($child_record['action'] == "delete") {
							unset($child_record['action']);

							$result = DB::table($form_table)->where($form_config['child_foreign_key'], $form_config['link_field_value'])
								->where('id', $child_record['id'])->delete();
						}
					}
				}
			}
		}

		return $result;
	}


	// Delete the record from the database
	public static function delete($form_config, $email_id = null) {
		$user_role = self::get_from_session('role');

		if ($user_role == 'Administrator') {
			return self::delete_record($form_config, $email_id);
		}
		else {
			$allowed = PermController::role_wise_modules($user_role, "Delete", $form_config['module']);
			if ($allowed) {
				return self::delete_record($form_config, $email_id);
			}
			else {
				self::put_to_session('success', "false");
				return back()->withInput()->with(['msg' => 'You are not authorized to delete "'. $form_config['module_label'] . '" record(s)']);
			}
		}
	}


	// Delete's record from database
	public static function delete_record($form_config, $email_id = null) {
		if ($form_config['link_field_value']) {
			$data = DB::table($form_config['table_name'])->where($form_config['link_field'], $form_config['link_field_value'])->first();

			if ($data) {
				// if record found then only delete it
				$result = DB::table($form_config['table_name'])->where($form_config['link_field'], $form_config['link_field_value'])->delete();

				if ($result) {
					// delete child tables if found
					if (isset($form_config['child_tables']) && isset($form_config['child_foreign_key'])) {
						foreach ($form_config['child_tables'] as $child_table) {
							DB::table($child_table)->where($form_config['child_foreign_key'], $form_config['link_field_value'])->delete();
						}
					}

					// delete user if modules come under user_via_modules
					if (in_array($form_config['module'], self::$user_via_modules)) {
						self::user_form_action($email_id, $form_config['module'], "delete");
					}

					self::put_to_session('success', "true");
					return redirect($form_config['list_view'])->with(['msg' => $form_config['module_label'] . ': "' . $form_config['link_field_value'] . '" deleted successfully']);
				}
				else {
					self::put_to_session('success', "false");
					return redirect($form_config['list_view'])->with(['msg' => 'Oops! Some problem occured while deleting. Please try again']);
				}

				// deletes the avatar file if any
				if (isset($data->avatar) && $data->avatar) {
					File::delete(public_path().$data->avatar);
				}
			}
			else {
				self::put_to_session('success', "false");
				return redirect($form_config['list_view'])->with(['msg' => 'No record(s) found with the given data']);
			}
		}
		else {
			self::put_to_session('success', "false");
			return redirect($form_config['list_view'])->with(['msg' => 'Cannot delete the record. "' . $form_config['link_field'] . '" is not set']);
		}
	}


	// Returns the array of data from request with some common data
	public static function populate_data($request, $form_config, $action = null) {

		$form_data = $request->all();
		unset($form_data["_token"]);

		if ($request->hasFile('avatar') && isset($form_config['avatar_folder']) && $form_config['avatar_folder']) {
			$form_data['avatar'] = self::create_avatar_path($request->file('avatar'), $form_config['avatar_folder']);
		}

		// get the table schema
		$table_schema = self::get_table_schema($form_config['table_name']);

		foreach ($form_data as $column => $value) {
			if (isset($table_schema[$column]) && $table_schema[$column] == "date") {
				$value = date('Y-m-d', strtotime($value));
			}
			elseif (isset($table_schema[$column]) && $table_schema[$column] == "datetime") {
				$value = date('Y-m-d H:i:s', strtotime($value));
			}
			// checking is array is important to eliminate convert type for child tables
			elseif (!is_array($value)) {
				self::convert_type($value, $table_schema[$column]);
			}

			if ($value) {
				if (isset($form_config['child_tables']) && in_array($column, $form_config['child_tables'])) {
					$data[$column] = $value;
				}
				else {
					$data[$form_config['table_name']][$column] = $value;
				}
			}
			else {
				if ($form_config['link_field_value']) {
					$data[$form_config['table_name']][$column] = null;
				}
			}
		}


		$data = self::merge_common_data($data, $form_config, $action);
		// echo json_encode($data);
		// exit();
		return $data;
	}


	// converts the type of request value to the type to be inserted in db
	public static function convert_type($value, $type_name) {
		if ($type_name == "decimal") {
			$type_name = "float";
		}
		elseif ($type_name == "text") {
			$type_name = "string";
		}

		settype($value, $type_name);
	}


	// Returns the array of data from request with some common data and child data
	public static function merge_common_data($data, $form_config, $action = null) {
		$owner = $last_updated_by = self::get_from_session('login_id');
		$created_at = $updated_at = date('Y-m-d H:i:s');

		$parent_table = $form_config['table_name'];

		foreach ($data as $table => $table_data) {
			if ($table == $parent_table) {
				$data[$table]['last_updated_by'] = $last_updated_by;
				$data[$table]['updated_at'] = $updated_at;

				if ($action == "create") {
					$data[$table]['owner'] = $owner;
					$data[$table]['created_at'] = $created_at;
				}

				// check if module come under generate no modules list
				if (in_array($form_config['module'], array_keys(self::$generate_no_modules)) && $action == "create") {
					$parent_field_name = implode(array_keys(self::$generate_no_modules[$form_config['module']]));
					$prefix = self::$generate_no_modules[$form_config['module']][$parent_field_name];

					// check if generated no is already present in record
					$valid_no = false;
					do {
						if ($form_config['module'] == "Booking") {
							$generated_no = $prefix . self::generate_booking_no($parent_table, $data);
						}
						else {
							$generated_no = $prefix . self::generate_password(6, "only_numbers");
						}
						$existing_no = DB::table($table)->where($parent_field_name, $generated_no)->pluck($parent_field_name);
						if (!$existing_no) {
							$valid_no = true;
						}
					} while ($valid_no == false);

					$data[$table][$parent_field_name] = $generated_no;
				}
			}
			else {
				foreach (array_values($table_data) as $index => $child_record) {
					if (isset($data[$table][$index]['id']) && $data[$table][$index]['id']) {
						$data[$table][$index]['id'] = (int) $data[$table][$index]['id'];
					}
					// insert foreign key of child table which connects to parent table link field
					if (isset($data[$parent_table]) && isset($data[$parent_table][$form_config['link_field']])) {
						$data[$table][$index][$form_config['child_foreign_key']] = $data[$parent_table][$form_config['link_field']];
					}
					if (isset($form_config['copy_parent_fields']) && isset($data[$parent_table])) {
						foreach ($form_config['copy_parent_fields'] as $parent_field => $child_field) {
							$data[$table][$index][$child_field] = $data[$parent_table][$parent_field];
						}
					}

					$data[$table][$index]['last_updated_by'] = $last_updated_by;
					$data[$table][$index]['updated_at'] = $updated_at;

					if ($action == "create") {
						$data[$table][$index]['owner'] = $owner;
						$data[$table][$index]['created_at'] = $created_at;
					}
				}
			}
		}

		return $data;
	}


	// performs form actions for user table
	public static function user_form_action($request, $module, $action, $user_avatar = null) {
		$user = DB::table('tabUser');

		if ($action == "delete") {
			$result = $user->where('login_id', $request)->delete();
		}
		else {
			$user_data = array(
				"full_name" => $request->full_name,
				"login_id" => $request->email_id,
				"email" => $request->email_id,
				"status" => ($module == "Guest") ? "Inactive" : $request->status,
				"last_updated_by" => self::get_from_session('login_id'), 
				"updated_at" => date('Y-m-d H:i:s')
			);

			if (isset($user_avatar) && $user_avatar) {
				$user_data["avatar"] = $user_avatar;
			}

			if ($action == "create") {
				$password = FormController::generate_password();
				$user_data["password"] = bcrypt($password);
				$user_data["role"] = $module;
				$user_data["owner"] = self::get_from_session('login_id');
				$user_data["created_at"] = date('Y-m-d H:i:s');

				$result = $user->insert($user_data);
				$user_data['generated_password'] = $password;
				// send password to user via email
				if (SettingsController::get_app_setting('email') == "Active") {
					EmailController::send(null, $request->email_id, "Basecamp Account Password", $user_data, $module);
				}
			}
			elseif ($action == "update") {
				$result = $user->where('login_id', $request->email_id)->update($user_data);
			}
		}

		return $result;
	}


	// creates avatar name
	public static function create_avatar_path($avatar_file, $avatar_folder) {
		/* custom avatar file name */
		$avatar_name = date('YmdHis').".".$avatar_file->getClientOriginalExtension();
		/* full avatar path */
		$avatar_full_path = $avatar_folder ."/". $avatar_name;

		return $avatar_full_path;
	}


	// checks for an existing record in the database
	public static function check_existing($request, $form_config) {
		$existing_record = false;

		if ($request->$form_config['link_field']) {
			$existing_record = DB::table($form_config['table_name'])
				->where($form_config['link_field'], $request->$form_config['link_field'])
				->first();
		}

		return $existing_record ? true : false;
	}


	// returns the value from session if auth check else return to login
	public static function get_from_session($key) {
		if (Session::get('role') == "Website User") {
			return Session::get($key);
		}
		else {
			if (Auth::check() && Session::has($key) && Session::get($key)) {
				return Session::get($key);
			}
			else {
				return false;
			}
		}
	}


	// sets the value to session if auth check else return to login
	public static function put_to_session($key, $value) {
		if (Auth::check()) {
			return Session::put($key, $value);
		}
		else {
			return false;
		}
	}


	// generates booking no based on fixed logic
	public static function generate_booking_no($table, $data) {
		$basecamp_no = preg_replace("/[^0-9]/", "", $data[$table]['basecamp_name']);
		$basecamp_no = sprintf("%02s", $basecamp_no);
		$check_in_month = date('m', strtotime($data[$table]['check_in_date']));
		$current_year = date('y');

		if (date('d') == 01 && date('m') == 01) {
			$current_year_records = DB::table($table)
				->whereRaw('YEAR("created_at") = YEAR(CURDATE())')
				->first();

			if (!$current_year_records) {
				DB::table('tabDocumentNo')
					->where('document_name', $form_config['module'])
					->update(['last_document_no', 1]);

				$serial_no == '1';
			}
		}
		else {
			$serial_no = DB::table('tabDocumentNo')
				->where('document_name', substr($table, 3))
				->pluck('last_document_no');
		}

		$serial_no = sprintf("%03s", $serial_no);

		$booking_no = $basecamp_no . $check_in_month . $current_year . (string) $serial_no;
		return $booking_no;
	}


	// generates a new random password
	public static function generate_password($length = null, $only_numbers = null) {
		if ($only_numbers) {
			$alphabet = "0123456789";
		}
		else {
			$alphabet = "abcdefghijklmnopqrstuwxyz_ABCDEFGHIJKLMNOPQRSTUWXYZ0123456789@#$.";
		}

		$pass = array(); //remember to declare $pass as an array
		$alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
		$length = $length ? $length : 10;
		for ($i = 0; $i < $length; $i++) {
			$n = rand(0, $alphaLength);
			$pass[] = $alphabet[$n];
		}

		return implode($pass); //turn the array into a string
	}


	// get controller name which has called this controller function
	// pass Route instance to this function
	public static function get_controller_name($route) {
		$route_action = $route->getAction();
		return explode("@", class_basename($route_action['controller']))[0];
	}


	// check if email id is already registered
	public static function check_email_id($form_config, $email_id) {
		$email_tables = ['tabClient', 'tabGuest', 'tabCook'];

		foreach ($email_tables as $table_name) {
			$query = DB::table($table_name)->where('email_id', $email_id);
			if ($form_config['link_field_value'] && $table_name == $form_config['table_name']) {
				$query = $query->where($form_config['link_field'], '!=', $form_config['link_field_value']);
			}

			$user_email_id = $query->pluck('email_id');

			if ($user_email_id) {
				break;
			}
		}

		if ($user_email_id) {
			Session::put('success', 'false');
			return back()->withInput()->with(['msg' => 'Email ID: "' . $user_email_id . '" is already registered.']);
		}
		else {
			Session::put('success', 'true');
			return true;
		}
	}


	// returns table column name and column type
	public static function get_table_schema($table) {
		$columns = DB::connection()
			->getDoctrineSchemaManager()
			->listTableColumns($table);

		$table_schema = [];

		foreach($columns as $column) {
			$table_schema[$column->getName()] = $column->getType()->getName();
		}

		return $table_schema;
	}
}