<?php

namespace App\Http\Controllers;

use App;
use Session;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

class FormActions extends Controller
{
	public static $controllers_path = "App\\Http\\Controllers";

	/**
	 * Display the form view
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function show($module_name = null, $id = null) {
		$this->set_form_config($module_name);
		$this->form_config['link_field_value'] = $id;
		$show_response = FormController::show($this->form_config);
		return $this->make_action_based_on_response($show_response);
	}


	/**
	 * Stores/Saves the form value to the database
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function save(Request $request, $module_name = null, $id = null) {
		$this->set_form_config($module_name);
		$this->form_config['link_field_value'] = $id;
		$save_response = FormController::save($request, $this->form_config);
		return $this->make_action_based_on_response($save_response, 'form_view');
	}


	/**
	 * Deletes the form value from the database
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function delete($module_name = null, $id = null) {
		$this->set_form_config($module_name);
		$this->form_config['link_field_value'] = $id;
		$delete_response = FormController::delete($this->form_config);
		return $this->make_action_based_on_response($delete_response, 'list_view');
	}


	// get form config from specific controller
	public function set_form_config($module_name) {
		$module_controller = App::make(self::$controllers_path . "\\" . ucwords(camel_case($module_name)) . "Controller");
		$this->form_config = $module_controller->form_config;
	}


	// redirect to page based on api response
	public function make_action_based_on_response($response, $action = null) {
		$response = json_decode($response->getContent());

		if (isset($response->status_code) && $response->status_code == 200) {
			$data = json_decode(json_encode($response->data), true);
			$form_data = isset($data['form_data']) ? $data['form_data'] : [];

			if ($action && $action == 'list_view') {
				return redirect($this->form_config['list_view'])
					->with(['msg' => $response->message]);
			}
			elseif ($action && $action == 'form_view') {
				$form_view = $this->form_config['form_view'];
				$form_link_field_value = $form_data['tab'.$this->form_config['module']][$this->form_config['link_field']];

				return redirect($form_view.$form_link_field_value)
					->with(['msg' => $response->message]);
			}
			else {
				return view('templates.form_view')->with($data);
			}
		}
		elseif (isset($response->status_code) && $response->status_code == 400) {
			self::put_to_session('success', "false");
			return back()->withInput()->with(['msg' => $response->message]);
		}
		elseif (isset($response->status_code) && $response->status_code == 401) {
			self::put_to_session('success', "false");
			return back()->withInput()->with(['msg' => $response->message]);
		}
		elseif (isset($response->status_code) && $response->status_code == 404) {
			if ($action && $action == 'list_view') {
				return redirect($this->form_config['list_view'])
					->with(['msg' => $response->message]);
			}
			elseif ($action && $action == 'form_view') {
				return redirect($this->form_config['form_view'].$this->form_config['link_field_value'])
					->with(['msg' => $response->message]);
			}
			else {
				abort('404');
			}
		}
		elseif (isset($response->status_code) && $response->status_code == 500) {
			if ($action && $action == 'list_view') {
				return redirect($this->form_config['list_view'])
					->with(['msg' => $response->message]);
			}
			elseif ($action && $action == 'form_view') {
				return redirect($this->form_config['form_view'].$this->form_config['link_field_value'])
					->with(['msg' => $response->message]);
			}
		}
	}
}