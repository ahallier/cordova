<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Pages_model extends MY_Model {
	/**
	 * Holds an array of tables used.
	 *
	 * @var array
	 */
  public $tables = array();

	public function __construct() {
		parent::__construct();
    $this->load->config('variation_database');

		//initialize db tables data
		$this->tables = $this->config->item('tables');
	}

  /**
   * Get All Version Info
   *
   * Returns all information for all versions of the database.
   *
   * @author Sean Ephraim
   * @access public
   * @return object All versioning info
   */
  public function get_all_version_info() {
    $query = $this->db
                  ->get($this->tables['versions']);
    return $query->result();
  }

}
/* End of file pages_model.php */
/* Location: ./application/models/pages_model.php */
