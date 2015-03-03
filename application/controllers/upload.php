<?php
class Upload extends CI_Controller {

  function __construct()
  {
    parent::__construct();
    $this->load->helper(array('form', 'url'));
  }

  function index()
  {
    $this->load->view('upload_genes', array('error' => ' ' ));
  }

  function do_upload()
  {
    $config['upload_path'] = '/var/www/html/cordova_arh/applications/controlers/uploads/';

    $this->load->library('upload', $config);

    if ( ! $this->upload->do_upload())
    {
      $error = array('error' => $this->upload->display_errors());

      $this->load->view('upload_genes', $error);
    }
    else
    {
      $data = array('upload_data' => $this->upload->data());
      $this->load->view('query_public_database', $data);
    }
  }
}
                                                                                                      ?>
