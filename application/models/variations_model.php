<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Variations_model extends MY_Model {
	/**
	 * Array of database tables used.
	 *
	 * @var array
	 */
  public $tables = array();

	public function __construct() {
		parent::__construct();

		// Initialize db tables data
		$this->tables = $this->config->item('tables');
	}

  /**
   * Remove Temp Files
   *
   * Removes all temporary files that get created during the annotation
   * process. Any files that BEGIN with the provided string will be
   * removed. For example if 'foo' is provided, then the bash command
   * 'rm foo*' will be performed.
   *
   * @author Sean Ephraim
   * @access public
   * @param  string $prefix Filename prefix for all files to be removed
   */
   public function remove_temp_files($prefix) {
     exec("rm $prefix*");
   }

  /**
   * Format Hg19 Position
   *
   * This function will return the correct format for the
   * Hg19 genomic position. This means:
   *   - The user does not have to worry about case-sensitivity
   *     when entering a variation
   *       - i.e. "CHR1:41283868:g>a" vs. "chr1:41283868:G>A"
   *   - The user does not have to include "chr" in the name
   *     of the variation
   *       - i.e. "1:41283868:G>A" vs. "chr1:41283868:G>A"
   *
   * If the second parameter ($for_dbnsfp) is TRUE, then the input
   * will be formatted for use with dbNSFP. This means:
   *   - chr1:41283868:G>A becomes 1 41283868 G A
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string  $variation Genomic position (Hg19) (unformatted)
   * @param   boolean $for_dbnsfp Format as input into dbNSFP search program
   * @return  string  Genomic position (Hg19) (formatted)
   */
   public function format_hg19_position($variation, $for_dbnsfp = FALSE) {
    /*
     Get the parts of the variation
     For a variant such as chr1:41283868:G>A, the parts would be
       [0] => chr1
       [1] => 41283868
       [2] => G>A
    */
    $parts = explode(':', $variation);

    // Format part 0 of the variation...
    // But first, does the part contain "chr" in it?
    if (stristr($parts[0], 'chr') !== FALSE) {
      // Yes, the part contains "chr", now make it all lowercase
      $parts[0] = strtolower($parts[0]);
    }
    else {
      // No, the part doesn't contain "chr", so add it
      $parts[0] = 'chr' . $parts[0];
    }

    // Make sure X and Y are uppercase (if applicable)
    $parts[0] = str_replace("x", "X", $parts[0]);
    $parts[0] = str_replace("y", "Y", $parts[0]);

    // Make part 2 (the alleles) uppercase
    $parts[2] = strtoupper($parts[2]);

    // Put all 3 parts back together and return it
    $variation = implode(':', $parts);

    if ($for_dbnsfp) {
      // Format the variation for dbNSFP
      // |--> chr1:41283868:G>A becomes 1 41283868 G A
      $variation = str_replace('chr', '', $variation);
      $variation = str_replace(array(':', '>'), "\t", strtoupper($variation));
    }

    return $variation;
   }

  /**
   * Get dbSNP ID
   *
   * Queries dbSNP in order to find a variant's dbSNP ID. This function will
   * retrieve the HTML for a dbSNP result based on a variant's chromosome number/letter
   * and chromosomal position.
   *
   * *KNOWN ISSUE*: This function will only return a SNP ID if 1 (and only 1) SNP ID is
   *                associated with that position. Otherwise it will return NULL, even
   *                if 2 or more SNP IDs exist.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string  $variation Genomic Position (Hg19) (machine name: variation)
   * @return  mixed   dbSNP ID if only 1 is found; else NULL if more than 1 found
   */
  public function get_dbsnp_id($variation) {
    /*
     Get the parts of the variation
     For a variant such as chr1:41283868:G>A, the parts would be
       [0] => chr1
       [1] => 41283868
       [2] => G>A
    */
    $parts = explode(':', $variation);

    // Get the chromosome number/letter
    $chr = substr($parts[0], strpos($parts[0], 'chr') + 3); 
    // Get the chromosomal position
    $pos = $parts[1];
    // Query dbSNP
    $url = "http://www.ncbi.nlm.nih.gov/snp/?term=((".$chr."[Chromosome])+AND+".$pos."[Base+Position])&amp;report=DocSet";
    $html = shell_exec("curl -g --silent --max-time 5 --location \"$url\"");
    $lines = preg_split('/\s+/', $html);
    $dbsnp = NULL;
    $dbsnps = array();
    // Fetch the SNP ID from the HTML
    foreach ($lines as $line) {
      if (strstr($line, "SNP_ID=")) {
        // Strip away everything except the SNP ID number
        $dbsnp = 'rs'.substr($line, strpos($line, 'SNP_ID=') + 7); 
        if ( ! in_array($dbsnp, $dbsnps)) {
          $dbsnps[] = $dbsnp;
        }   
      }   
    }   
  
    // return dbSNP ID (if only 1 was found)
    if (count($dbsnps) == 1) {
      return trim($dbsnps[0]);
    }   
    else {
      return NULL;
    } 
  }

  /**
   * Get Annotation Data
   *
   * NOTE: This function should ONLY be used to *add* new data to the variation database.
   *       Never use this function for variants that already exist in the database
   *       because it will take way too long (it pulls from several large databases).
   *       For loading variant data that's already in the variation database, use
   *       get_variant_by_id() or get_variants_by_position() instead (they pull from
   *       the variation database, which is MUCH faster) as they are much more practical
   *       to use with things like the API and views.
   *
   * Uses the annotation tool to retrieve variant data based on the Genomic Position (Hg19).
   * This function will run variant annotation, parse the variant data, and return the fields
   * that are relevant to the database.
   *
   * Be sure to specify the path to the annotation tool in the 
   * application/config/variation_database.config file.
   *
   * Sample structure of the output array:
   * $data['variation']              
   * $data['gene']                   
   * $data['hgvs_nucleotide_change'] 
   * $data['hgvs_protein_change']    
   * $data['variantlocale']          
   *
   * @author Sean Ephraim
   * @access public
   * @param  string $variation Genomic Position (Hg19) (machine name: variation)
   * @return mixed  Array of fields to be autofilled; negative number on error
   */
  public function get_annotation_data($variation) {

    // Path to annotation tool and associated files
    $annot_path = $this->config->item('annotation_path');
    $ruby_path = $this->config->item('ruby_path');
    $run_script = $annot_path.'kafeen.rb';
    $id = random_string('unique'); // unique ID (needed to avoid file collisions)
    $f_in = $annot_path."tmp/$id.in"; // annotation input file
    $f_out = $annot_path."tmp/$id.out"; // annotation output file
    $f_errors = $annot_path."tmp/$id.error_log"; // annotation errors file

    /* Is the annotation tool installed and properly referenced? */
    if (empty($annot_path) || ! file_exists($run_script)) {
      // ERROR: annotation tool has not been properly configured
      return -503;
    }

    /* BEGIN RUNNING ANNOTATION */

    // Delete old input/output files if they exist (just to be safe)
    $this->remove_temp_files($f_in);

    $variation = $this->format_hg19_position($variation);

    // Create annotation input file
    $success = file_put_contents($f_in, $variation);
    if ($success === FALSE) die("The input file for annotation could not be created. Please contact the administrator.\n");
    if ( ! chmod($f_in, 0777)) die("Annotation input file must have correct permissions. Please contact the administrator.");

    // Run annotation (logs are written to $annot_path/tmp/log)
    if ($ruby_path == '') {
      $ruby_path = 'ruby'; // use default location if blank
    }
    exec("$ruby_path $run_script --progress --in $f_in --out $f_out > ".$annot_path."tmp/log 2>&1");
    
    // Check if annotation returned an error
    if (file_exists($f_errors)) {
      $contents = file_get_contents($f_errors);
      if (strpos($contents, 'ERROR_NOT_SUPPORTED_MUTATION_TYPE')) {
        // ERROR: unsupported mutation type
        $this->remove_temp_files($f_in);
        return -400;
      }

      if (strpos($contents, 'ERROR_NO_MATCHING_REFSEQ')) {
        // ERROR: no matching refseq (annotation returned nothing)
        $this->remove_temp_files($f_in);
        return -501;
      }
    }
    
    // Get annotation data and cleanup
    $contents = file_get_contents($f_out);
    $this->remove_temp_files($f_in);

    /* END RUNNING ANNOTATION */

    if (empty($contents)) {
      // ERROR: No data found for this variant
      return -404;
    }

    // Turn column data into associative array
    $lines = explode("\n", $contents);
    $keys = explode("\t", $lines[0]);
    $values = explode("\t", $lines[1]);
    $annot_result = array_combine($keys, $values);
    
    // Convert all '.' or '' values to NULL
    foreach ($annot_result as $key => $value) {
      if ($value === '.' || $value === '') {
        $annot_result[$key] = NULL;
      }
    }

    /**
     * NOTE: Each key is the exact same name of a column in the database.
     */
    $data = array(
        'variation'              => $annot_result['variation'],
        'gene'                   => $annot_result['gene'],
        'hgvs_nucleotide_change' => $annot_result['hgvs_nucleotide_change'],
        'hgvs_protein_change'    => $annot_result['hgvs_protein_change'],
        'variantlocale'          => $annot_result['variantlocale'],
        'pathogenicity'          => $annot_result['pathogenicity'],
        'dbsnp'                  => $annot_result['dbsnp'],
        'phylop_score'           => $annot_result['phylop_score'],
        'phylop_pred'            => $annot_result['phylop_pred'],
        'sift_score'             => $annot_result['sift_score'],
        'sift_pred'              => $annot_result['sift_pred'],
        'polyphen2_score'        => $annot_result['polyphen2_score'],
        'polyphen2_pred'         => $annot_result['polyphen2_pred'],
        'lrt_score'              => $annot_result['lrt_score'],
        'lrt_pred'               => $annot_result['lrt_pred'],
        'mutationtaster_score'   => $annot_result['mutationtaster_score'],
        'mutationtaster_pred'    => $annot_result['mutationtaster_pred'],
        'gerp_nr'                => $annot_result['gerp_nr'],
        'gerp_rs'                => $annot_result['gerp_rs'],
        'gerp_pred'              => $annot_result['gerp_pred'],
        'lrt_omega'              => $annot_result['lrt_omega'],
        'evs_ea_ac'              => $annot_result['evs_ea_ac'],
        'evs_ea_af'              => $annot_result['evs_ea_af'],
        'evs_aa_ac'              => $annot_result['evs_aa_ac'],
        'evs_aa_af'              => $annot_result['evs_aa_af'],
        'otoscope_aj_ac'         => $annot_result['otoscope_aj_ac'],
        'otoscope_aj_af'         => $annot_result['otoscope_aj_af'],
        'otoscope_co_ac'         => $annot_result['otoscope_co_ac'],
        'otoscope_co_af'         => $annot_result['otoscope_co_af'],
        'otoscope_us_ac'         => $annot_result['otoscope_us_ac'],
        'otoscope_us_af'         => $annot_result['otoscope_us_af'],
        'otoscope_jp_ac'         => $annot_result['otoscope_jp_ac'],
        'otoscope_jp_af'         => $annot_result['otoscope_jp_af'],
        'otoscope_es_ac'         => $annot_result['otoscope_es_ac'],
        'otoscope_es_af'         => $annot_result['otoscope_es_af'],
        'otoscope_tr_ac'         => $annot_result['otoscope_tr_ac'],
        'otoscope_tr_af'         => $annot_result['otoscope_tr_af'],
        'otoscope_all_ac'        => $annot_result['otoscope_all_ac'],
        'otoscope_all_af'        => $annot_result['otoscope_all_af'],
        'tg_afr_ac'              => $annot_result['tg_afr_ac'],
        'tg_afr_af'              => $annot_result['tg_afr_af'],
        'tg_eur_ac'              => $annot_result['tg_eur_ac'],
        'tg_eur_af'              => $annot_result['tg_eur_af'],
        'tg_amr_ac'              => $annot_result['tg_amr_ac'],
        'tg_amr_af'              => $annot_result['tg_amr_af'],
        'tg_asn_ac'              => $annot_result['tg_asn_ac'],
        'tg_asn_af'              => $annot_result['tg_asn_af'],
        'tg_all_ac'              => $annot_result['tg_all_ac'],
        'tg_all_af'              => $annot_result['tg_all_af'],
    );
    
    // Credits for the comments
    $credits = array();
    $freqs = $this->config->item('frequencies'); // frequencies to display
    $keys = array_keys($data);
    // ESP6500 credit
    if (in_array('evs', $freqs)) {
      if ($this->give_credit_to('evs', $data)) {
        $credits[] = 'ESP6500';
      }
    }
    // 1000 Genomes credit
    if (in_array('1000genomes', $freqs)) {
      if ($this->give_credit_to('tg', $data)) {
        $credits[] = '1000 Genomes';
      }
    }
    // OtoSCOPE credit
    if (in_array('otoscope', $freqs)) {
      if ($this->give_credit_to('otoscope', $data)) {
        $credits[] = 'OtoSCOPE';
      }
    }
    // Always give credit to dbNSFP 2
    $credits[] = 'dbNSFP 2';
    $credits = array_filter($credits);

    // Put together the comments
    $comments = array('Manual curation in progress.');
    if ( ! empty($credits)) {
      $comments[] = 'Record generated from: ' . implode(', ', $credits) . '.';
    }
    $data['comments'] = implode(' ', $comments);

    return $data;
  }

  /**
   * Give Credit To
   *
   * Decides whether or not credit should be given to certain data
   * sources such as EVS, 1000 Genomes, etc. For example, if 
   * $data['evs_ea_an'] contains data, then credit should be given to
   * EVS in the comments section. To test for this, an example call
   * would be
   *   give_credit_to('evs', $data)
   * and if any array element in $data such that $data['evs_*'] is 
   * non-empty, then TRUE will be returned.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string  $prefix  Prefix to check for
   * @param   array   $data  Associate array of variant data
   * @return  boolean  TRUE if credit should be given, else FALSE
   */
  public function give_credit_to($prefix, $data)
  {
    $prefix = $prefix . '_';
    foreach ($data as $key => $value) {
      if (strstr($key, $prefix) !== FALSE) {
        if ($data[$key] !== NULL && $data[$key] !== '') {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Create New Variant
   *
   * Adds a variant to the database. This will first create an empty row
   * in the live table in order to create a unique ID for the variant.
   * Subsequently, the new record will get copied to the queue (in order
   * to maintain the unique ID). Any real data will get added to this
   * record in the queue and will not be seen on the live site until a
   * batch release is performed.
   *
   * Setting the second parameter to TRUE allows you to turn on manual mode
   * in order to insert a variant without any autofill data from annotation.
   * Use this with care, as it also bypasses any checks for duplication
   * or improper formatting.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string   $variation Genomic Position (Hg19) (machine name: variation)
   * @param   boolean  $manual_mode (optional) Bypass annotation to manually insert variant
   * @return  int      Variant ID (positive integer) on success; negative integer on error
   */
  public function create_new_variant($variation, $manual_mode = FALSE, $variant_cadi = FALSE, $variant_data = array())
  {
    // Variation database tables
    $vd_live  = $this->tables['vd_live'];
    $vd_queue = $this->tables['vd_queue'];

    // Check if variation is already in the live and/or queue database
    $query_live  = $this->db->get_where($vd_live, array('variation' => $variation), 1);
    $query_queue = $this->db->get_where($vd_queue, array('variation' => $variation), 1);

    if ($manual_mode !== TRUE) {
      if ($query_live->num_rows() > 0 || $query_queue->num_rows() > 0) {
        // ERROR: Variant exists in the database
        return -409;
      }
    }

    // SUCCESS: Variant does NOT already exist in the database
    if ($manual_mode === TRUE and $variant_cadi !== TRUE) {
      // Manually set the variation
      $annot_data = array('variation' => $variation,
                          'pathogenicity' => 'Unknown significance',
                          'comments' => 'Manual curation in progress.',
                         );
    }
    if ($variant_cadi === TRUE) {
      $annot_data = $variant_data;
    }
    else {
      // Try running annotation query script...
      $annot_data = $this->get_annotation_data($variation);

      if (is_numeric($annot_data) && $annot_data < 0) {
        // ERROR: annotation returned an error (aka a negative integer)
        return $annot_data;
      }
    }

    // Create empty row in live table and get its unique ID
    $keys = $this->get_variant_fields($vd_live);
    $null_data = array_fill_keys($keys, NULL); // set all values to NULL
    $this->db->insert($vd_live, $null_data);
    $id = $this->db->insert_id();

    // Log it!
    $username = $this->ion_auth->user()->row()->username;
    $gene = empty($queue_data['gene']) ? 'MISSING_GENE' : $queue_data['gene'];
    $protein = empty($queue_data['hgvs_protein_change']) ? 'MISSING_PROTEIN_CHANGE' : $queue_data['hgvs_protein_change'];
    activity_log("User '$username' added new variant $gene|$protein|$variation", 'add');
   
    // Then create a row for this variant in the queue with matching unique ID
    $queue_data = array_merge($null_data, $annot_data); // overwrite non-NULL values
    $queue_data['id'] = $id;
    $this->variations_model->update_variant_in_queue($id, $queue_data);

    // Lastly, create a review record for this variant
    $this->variations_model->update_variant_review_info($id);

    return $id;
  }

  /**
   * Remove Variant From Queue
   *
   * This removes all variant changes from the queue. In addition
   * it removes the empty row that was created for it in the live
   * table as well as any review information for it.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int $id Variant unique ID
   */
  public function remove_all_changes($id)
  {
    // Variation database tables
    $vd_live  = $this->tables['vd_live'];
    $vd_queue = $this->tables['vd_queue'];
    $reviews = $this->tables['reviews'];

    $variation = $this->db->get_where($vd_queue, array('id' => $id))->row_array();

    $this->db->delete($vd_queue, array('id' => $id)); 
    $this->db->delete($reviews, array('variant_id' => $id)); 

    // If variant is new, delete its empty record from the live data
    // (it's considered empty if 'variation' and 'hgvs_nucleotide_change' are NULL)
    $this->db->delete($vd_live, array('id' => $id, 'variation' => NULL, 'hgvs_nucleotide_change' => NULL));

    // Log it!
    $username = $this->ion_auth->user()->row()->username;
    $gene = empty($variation['gene']) ? 'MISSING_GENE' : $variation['gene'];
    $protein = empty($variation['hgvs_protein_change']) ? 'MISSING_PROTEIN_CHANGE' : $variation['hgvs_protein_change'];
    $variation = empty($variation['variation']) ? 'MISSING_VARIATION' : $variation['variation'];
    activity_log("User '$username' removed all changes for variant $gene|$protein|$variation", 'delete');
  }

  /**
   * Remove From Queue If Unchanged
   *
   * If no changes for this variant exist, then it will
   * be removed from the queue.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int $id Variant unique ID
   */
  public function remove_from_queue_if_unchanged($id)
  {
    // Variation database tables
    $vd_live  = $this->tables['vd_live'];
    $vd_queue = $this->tables['vd_queue'];
    $reviews = $this->tables['reviews'];

    $result = $this->get_unreleased_changes($id);

    if (empty($result[$id]['changes'])) {
      $this->db->delete($vd_queue, array('id' => $id)); 
      // If variant is new, delete its empty record from the live data
      // (it's considered empty if 'variation' and 'hgvs_nucleotide_change' are NULL)
      $this->db->delete($vd_live, array('id' => $id, 'variation' => NULL, 'hgvs_nucleotide_change' => NULL));
    }

  }

  /**
   * Get Variants By Gene
   *
   * Get all variants for a gene.
   *
   * @author Sean Ephraim
   * @access public
   * @param string $gene
   *    Gene name
   * @param string $columns
   *    (optional) Columns to select from the database; defaults to all
   * @return object Gene variations
   */
  public function get_variants_by_gene($gene, $columns=NULL, $table='vd_live')
  {
    // Optionally select specific columns (otherwise select *)
    if ($columns !== NULL && $columns !== '') {
      $this->db->select($columns);
    }

    $query = $this->db
                  ->where('gene', $gene)
                  ->order_by('variation', 'asc')
                  ->get($this->tables[$table]);
    return $query->result();
  }

  /**
   * Get Variants By Gene Letter
   *
   * Get all variants within a gene of the specified letter.
   *
   * @author Sean Ephraim
   * @access public
   * @param  char    $letter Gene name's first letter
   * @return object  Gene variations
   */
  public function get_variants_by_gene_letter($letter)
  {
    $query = $this->db
                  ->where('gene', $gene)
                  ->order_by('variation', 'asc')
                  ->get($this->tables['vd_live']);
    return $query->result();
  }

  /**
   * Get Variant By ID
   *
   * Get all data for a single variant. The data in the queue
   * takes precedence over the current data, therefore
   * if the variant exists in the queue, it will be returned.
   * If you don't want to query the queue table, then specify
   * the name of the table you want to query as the second
   * parameter.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int      $id Variant unique ID
   * @param  string   $table DB table to query
   * @return mixed    Variant data object or NULL
   */
  public function get_variant_by_id($id, $table = NULL)
  {
    // Default table is the queue
    if ($table === NULL) {
      $table = $this->tables['vd_queue'];
    }

    $query = $this->db
                  ->where('id', $id)
                  ->limit(1)
                  ->get($table);

    // This variant is not in the queue
    if ($query->num_rows() === 0 && $table !== $this->tables['vd_live']) {
      // Query the live DB instead
      return $this->get_variant_by_id($id, $this->tables['vd_live']);
    }

    // Still no result? This ID doesn't exist!
    if ($query->num_rows() === 0) {
      return NULL;
    }

    return $query->row();
  }

  /**
   * Get Variants By Position
   *
   * Get all data for a variants at a position. The data in the queue
   * takes precedence over the current data, therefore
   * if the variant exists in the queue, it will be returned.
   * If you don't want to query the queue table, then specify
   * the name of the table you want to query as the second
   * parameter. If the third parameter is TRUE, then fuzzy search
   * will be used, meaning that "chr13:20" will actually search
   * for "chr13:20*" and a variant such as "chr13:20796839" will be
   * included in the return results.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   string   $posisiton Genomic position w/o nucleotide change (i.e. chr13:20796839)
   * @param   string   $table DB table to query
   * @param   boolean  $fuzzy_search Use fuzzy search
   * @return  mixed    Variant data array or NULL
   */
  public function get_variants_by_position($position, $table = NULL, $fuzzy_search = FALSE)
  {
    if ($table === NULL) {
      $table = $this->tables['vd_queue'];
    }

    $this->db->like('variation', $position.":", 'after'); // for exact search
    if ($fuzzy_search) {
      $this->db->or_like('variation', $position, 'after'); // for fuzzy search
    }
    $query = $this->db->get($table);

    // This variant is not in the queue
    if ($query->num_rows() === 0 && $table !== $this->tables['vd_live']) {
      // Query the live DB instead
      return $this->get_variants_by_position($position, $this->tables['vd_live']);
    }

    // Still no result? This ID doesn't exist!
    if ($query->num_rows() === 0) {
      return NULL;
    }

    return $query->result();
  }

  /**
   * Get Variant Review
   *
   * Get all of the review information for a variant.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int    $variant_id Variant unique ID
   * @return object Variant data object or NULL
   */
  public function get_variant_review_info($variant_id)
  {
    $table = $this->tables['reviews'];
    $query = $this->db
                  ->where('variant_id', $variant_id)
                  ->limit(1)
                  ->get($table);

    return $query->row();
    // This variant is not in the queue
    if ($query->num_rows() > 0) {
      return $query->row();
    }
    else {
      return NULL;
    }

  }

  /**
   * Get Variant Reviews.
   *
   * Get the review information for all variants.
   *
   * @author Sean Ephraim
   * @access public
   * @return object  Variant data object or NULL
   */
  public function get_variant_reviews()
  {
    $table = $this->tables['reviews'];
    $query = $this->db
                  ->get($table);
    return $query->result();
  }

  /**
   * Variant Exists In Table
   * 
   * Check if a variant exists within a certain table.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int $id Variant unique ID
   * @param  string $table Table name
   * @return boolean
   */
  public function variant_exists_in_table($id, $table)
  {
    $query = $this->db
                  ->where('id', $id)
                  ->limit(1)
                  ->get($table);
    if ($query->num_rows() > 0) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get Variant Fields
   *
   * Get all variant fields specified in the database.
   *
   * @author Sean Ephraim
   * @access public
   * @param string $table Table name
   * @return array Fieldnames
   */
  public function get_variant_fields($table = NULL)
  {
    if ($table === NULL) {
      $table = $this->tables['vd_live'];
    }
    return $this->db->list_fields($table);
  }

  /**
   * Copy Variant Into Queue
   *
   * Copies a variant from the live site into the queue.
   *
   * @author Sean Ephraim
   * @access public
   * @param array $id Variant ID number
   */
  public function copy_variant_into_queue($id)
  {
    $variant = $this->get_variant_by_id($id); 
    $this->db->insert($this->tables['vd_queue'], $variant);
  }

  /**
   * Update Variant In Queue
   *
   * For each $data key, check if it exists as a field in the
   * queue. If it exists, update the value for the
   * given ID. Otherwise, create that variant in the queue.
   *
   * @author Sean Ephraim
   * @access public
   * @param int $id Variant ID number
   * @param array $data Assoc. array of variant fields
   * @return boolean
   */
  public function update_variant_in_queue($id, $data)
  {
    // Sanitize the data to be inserted
    // Remove fields that are not in this table
    $table_fields = $this->db->list_fields($this->tables['vd_queue']);
    foreach ($data as $key => $value) {
      if (in_array($key, $table_fields)) {
        $clean_data[$key] = trim($value);

        // Map all empty strings to NULL (or data may not save correctly in the database)
        if ($clean_data[$key] == '') {
          $clean_data[$key] = NULL;
        }
      }
    }

    $live_variant = (array) $this->variations_model->get_variant_by_id($id, $this->tables['vd_live']); 

    $query = $this->db->get_where($this->tables['vd_queue'], array('id' => $id), 1);
    if ($query->num_rows() > 0) {
      // Variant exists in queue, update it!
      $this->db
           ->where('id', $id)
           ->update($this->tables['vd_queue'], $clean_data);
    }
    else {
      // Variant does NOT exist in queue, copy it from the live table
      // ... But only if edits have actually been made
      $changes = array_diff_assoc($clean_data, $live_variant);
      if (empty($changes)) {
        return FALSE;
      }
      $this->copy_variant_into_queue($id);
      $this->update_variant_in_queue($id, $data);
    }

    // Log it!
    $username = $this->ion_auth->user()->row()->username;
    $variation = $this->db->get_where($this->tables['vd_queue'], array('id' => $id))->row_array();
    $gene = empty($variation['gene']) ? 'MISSING_GENE' : $variation['gene'];
    $protein = empty($variation['hgvs_protein_change']) ? 'MISSING_PROTEIN_CHANGE' : $variation['hgvs_protein_change'];
    $variation = empty($variation['variation']) ? 'MISSING_VARIATION' : $variation['variation'];
    activity_log("User '$username' edited variant $gene|$protein|$variation", 'edit');

    return TRUE;
  }

  public function push_data_live($confirmed_only = TRUE){
    // Set unlimited memory/time when retrieving all variants in the queue (queue could be quite large)
    ini_set('memory_limit', '-1');
    set_time_limit(0);
    
    $queueTable = $this->tables['vd_queue'];
    $liveTable = $this->tables['vd_live'];
    $reviewsTable = $this->tables['reviews'];
    $varLogTable = "variations_log"; 
    $varCountTable = $this->tables['variant_count'];
    //$databaseName = $this->db->database;
    $databaseName = $this->config->item('database_name');

    $deleteVariants = array();
    $deleteVariantsString = "";
    $updateVariants = array();    
    $updateVariantsString = "";
    if($confirmed_only){
      //get confirmed variations for deletion
      $deleteMe = "SELECT variant_id FROM $reviewsTable WHERE confirmed_for_release = 1 AND scheduled_for_deletion = 1";
      $deleteVariantsResult = mysql_query($deleteMe) or die("here1a");
      while($row = mysql_fetch_assoc($deleteVariantsResult))
      {
          $deleteVariants[] = $row['variant_id'];
      }
      $deleteVariantsString = implode(",", $deleteVariants);
      
      //get confirmed variations for updating
      $updateMe = "SELECT variant_id FROM $reviewsTable WHERE confirmed_for_release = 1";
      $updateVariantsResult = mysql_query($updateMe) or die('here6a');
      while($row = mysql_fetch_assoc($updateVariantsResult))
      {
          $updateVariants[] = $row['variant_id'];
      }
      $updateVariantsString = implode(",", $updateVariants);
    }
    else{
      //get all variants for deletion
      $deleteMe = "SELECT variant_id FROM $reviewsTable WHERE scheduled_for_deletion = 1";
      $deleteVariantsResult = mysql_query($deleteMe) or die("here1");
      while($row = mysql_fetch_assoc($deleteVariantsResult))
      {
          $deleteVariants[] = $row['variant_id'];
      }
      $deleteVariantsString = implode(",", $deleteVariants);
      
      //get all variants for updating
      $updateMe = "SELECT variant_id FROM $reviewsTable";
      $updateVariantsResult = mysql_query($updateMe) or die('here6');
      while($row = mysql_fetch_assoc($updateVariantsResult))
      {
          $updateVariants[] = $row['variant_id'];
      }
      $updateVariantsString = implode(",", $updateVariants);
    }
    
    //Delete Variants that need deleting
    if(sizeof($deleteVariants)){
      //add these live variants to log
      $q2 = "INSERT INTO $varLogTable SELECT * FROM $liveTable WHERE id IN ($deleteVariantsString)";
      $r2 = mysql_query($q2) or die($q2);
      //delete them from the reviews table
      $q3 = "DELETE FROM $reviewsTable WHERE id IN ($deleteVariantsString)";
      $r3 = mysql_query($q3) or die("here3");
      //delete them from the queue
      $q4 = "DELETE FROM $queueTable WHERE id IN ($deleteVariantsString)";
      $r4 = mysql_query($q4) or die("here4");
      //delete these from live varitions
      $q5 = "DELETE FROM $liveTable WHERE id IN ($deleteVariantsString)";
      $r5 = mysql_query($q5) or die("here5");
    }
    
    //Update Variants that need updating
    if(sizeof($updateVariants)>0){
      //add these live variants to log
      //$q7 = "INSERT INTO $varLogTable SELECT * FROM $liveTable WHERE id IN ($updateVariantsString)";
      
      //$q7 = "INSERT INTO $varLogTable SELECT * FROM $liveTable";
      //$r7 = mysql_query($q7) or die('here7');
      
      //Update them in the live varitions
      //$q10 = "UPDATE $liveTable FROM $queueTable WHERE id IN ($deleteVariantsString)";
      //$q10 = "UPDATE $liveTable SET A.gene = B.gene, A.pathogenicty = B.pathogenicty FROM $liveTable A INNER JOIN $queueTable B ON A.id = B.id WHERE A.id = B.id";
      $getCols = "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ',') AS columns FROM information_schema.columns WHERE table_schema = '$databaseName' AND table_name = '$liveTable'";
      $colsR = mysql_query($getCols) or die('hereCols');
      while($row = mysql_fetch_assoc($colsR))
      {
          $attributestring = $row['columns'];
          $attributeArray = explode(',', $attributestring);
          $setString = "";
          foreach ($attributeArray as $attribute){
            $setString = $setString."A.$attribute = B.$attribute,";
          }
      }
      $setString = rtrim($setString, ',');
      //return $setString;
      //$q10 = "UPDATE $liveTable A, $queueTable B SET $setString WHERE A.id = B.id AND A.id in ($updateVariantsString)";
      $q10 = "UPDATE $liveTable A, $queueTable B SET $setString WHERE A.id = B.id";
      //$q10 = "MERGE INTO $liveTable USING $queueTable ON $liveTable.id = $queueTable.id WHEN MATCHED THEN UPDATE SET $liveTable.gene = $queueTable.gene";
      $r10 = mysql_query($q10) or die('here8');
      //die($r10);
      //delete them from the reviews table
      //$q8 = "DELETE FROM $reviewsTable WHERE variant_id IN ($updateVariantsString)";
      $q8 = "DELETE FROM $reviewsTable";
      $r8 = mysql_query($q8) or die('here9');
      //delete them from the queue
      //$q9 = "DELETE FROM $queueTable WHERE id IN ($updateVariantsString)";
      $q9 = "DELETE FROM $queueTable";
      $r9 = mysql_query($q9) or die('here10');
    }
    //Update versions
    // Get new version number
    $new_version = ((int) $this->version);
    $genesQ = "SELECT count(*) AS num_genes FROM $liveTable GROUP BY gene";
    $genesR = mysql_query($genesQ) or die('here11');
    $genes =array();
    while($row = mysql_fetch_assoc($genesR)){
      $genes = $row['num_genes'];
    }
    // Update versions table
    $datetime = date('Y-m-d H:i:s');
    $data = array(
      'id'       => NULL,
      'version'  => $new_version,
      'created'  => $datetime,
      'updated'  => $datetime,
      'variants' => $this->db->count_all($liveTable),
      'genes'    => sizeof($genes),
    );
    $this->db->insert($this->tables['versions'], $data);
    $new_version_id = $this->db->query("SELECT MAX(id) FROM versions");
    
    //Drop current data from Variant Count
    $q10 = "DELETE FROM $varCountTable";
    $r10 = mysql_query($q10) or die('here12');
    //Update Variant Count
    $q11 = "INSERT INTO $varCountTable (gene, count) SELECT gene, count(*) FROM $liveTable GROUP BY gene";
    $r11 = mysql_query($q11) or die('here13');

    // Log it!
    //$username = $this->ion_auth->user()->row()->username;
    //activity_log("User '$username' released a new version of the database -- Version $new_version_id", 'release');
    
    return TRUE;
  }
  /**
   * Push Data Live
   *
   * Pushes data to live production. 
   *
   * Note that by default, the table with the highest version number
   * will automatically be the production data. Therefore, for example,
   * if you have variation data stored in tables 'dvd_1', 'dvd_2', and
   * 'dvd_3', then the 'dvd_3' data will be displayed on the public site.
   * This function will:
   *   - Copy the current production data (e.g. 'dvd_3') to a new table (e.g.
   *     'dvd_4'), then update the new table (e.g. 'dvd_4') to reflect the
   *     new changes
   *   - Update the 'versions' table
   *   - Create a new 'variant_count_' table
   *   - Backup the '_queue' table and 'reviews' table
   *   - Clear the '_queue' table and 'reviews' table of variants that were
   *     just released
   *
   * By default, only changes that have been confirmed for release are acutally
   * released. As an optional first parameter, you can turn this setting off
   * and release all changes regardless of confirmation status. To do this,
   * pass in FALSE for the first parameter.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   boolean   $confirmed_only
   *    (optional) Only release confirmed variants?
   * @return  boolean   TRUE on success, else FALSE
   */
  public function OLD_push_data_live($confirmed_only = TRUE)
  {
    // Set unlimited memory/time when retrieving all variants in the queue (queue could be quite large)
    ini_set('memory_limit', '-1');
    set_time_limit(0);

    //die(printf("HERE"));
    
    // Get all variants to update
    $new_records = $this->variations_model->get_all_variants($this->tables['vd_queue']);

    if ($confirmed_only === TRUE) {
      // Get only variants confirmed for deletion
      $delete_records = $this->db->get_where($this->tables['reviews'],
                                             array(
                                               'scheduled_for_deletion' => 1,
                                               'confirmed_for_release' => 1,
                                             ))->result();
      // Remove unconfirmed variants from update list
      foreach ($new_records as $key => $new_record) {
        $query = $this->db->get_where($this->tables['reviews'], array(
                                                                  'variant_id' => $new_record->id,
                                                                  'confirmed_for_release' => 0,
                                                                ));
        if ($query->num_rows > 0) {
          unset($new_records[$key]);
        }
      }
    }
    else {
      // Get all variants scheduled for deletion (confirmed or not)
      $delete_records = $this->db->get_where($this->tables['reviews'],
                                             array(
                                               'scheduled_for_deletion' => 1,
                                             ))->result();
    }

    if (empty($new_records) && empty($delete_records) && $this->version != 0) {
      // ERROR: no new records to update
      // NOTE: an empty update is only allowed for Version 0
      return FALSE;

    }

/*    // Create new variation table
    $new_live_table = $this->variations_model->get_new_version_name($this->tables['vd_live']);
    $copy_success = $this->variations_model->copy_table($this->tables['vd_live'], $new_live_table);
    if ( ! $copy_success) {
      // ERROR: problem copying live table
      return FALSE;
    }

    // Create new queue table
    $new_queue_table = $this->variations_model->get_new_version_name($this->tables['vd_queue']);
    $copy_success = $this->variations_model->copy_table($this->tables['vd_queue'], $new_queue_table);
    if ( ! $copy_success) {
      // ERROR: problem copying queue table
      return FALSE;
    }

    // Create new reviews table
    $new_reviews_table = $this->variations_model->get_new_version_name($this->tables['reviews']);
    $copy_success = $this->variations_model->copy_table($this->tables['reviews'], $new_reviews_table);
    if ( ! $copy_success) {
      // ERROR: problem copying reviews table
      return FALSE;
    }

    // Create new variant count table
    $new_count_table = $this->variations_model->get_new_version_name($this->tables['variant_count']);
    $copy_success = $this->variations_model->copy_table($this->tables['variant_count'], $new_count_table, FALSE);

    if ( ! $copy_success) {
      // ERROR: problem copying table
      return FALSE;
    }
*/

/*    if(((int) $this->version) == 0){
      //To maintain the versioning system, but speed up releases
      //Only rename the table, do not create and copy all of the data
    
      // Rename variation table
      $new_live_table = $this->variations_model->get_new_version_name($this->tables['vd_live']);
      if(!strcmp($new_live_table,$this->tables['vd_live'])){
        $rename_success = $this->variations_model->rename_table($this->tables['vd_live'], $new_live_table);
        if ( ! $rename_success) {
          // ERROR: problem copying live table
          return FALSE;
        }
      }

      // Rename queue table
      $new_queue_table = $this->variations_model->get_new_version_name($this->tables['vd_queue']);
      $rename_success = $this->variations_model->rename_table($this->tables['vd_queue'], $new_queue_table);
      if ( ! $rename_success) {
        // ERROR: problem copying queue table
        return FALSE;
      }

      // Rename new reviews table
      $new_reviews_table = $this->variations_model->get_new_version_name($this->tables['reviews']);
      $rename_success = $this->variations_model->rename_table($this->tables['reviews'], $new_reviews_table);
      if ( ! $rename_success) {
        // ERROR: problem copying reviews table
        return FALSE;
      }

      // Rename variant count table
      $new_count_table = $this->variations_model->get_new_version_name($this->tables['variant_count']);
      $rename_success = $this->variations_model->rename_table($this->tables['variant_count'], $new_count_table, FALSE);
      if ( ! $rename_success) {
        // ERROR: problem copying table
        return FALSE;
     }
    
      //Now we need to update versions
    
      // Get new version number
      $new_version = ((int) $this->version) + 1;

      // Update versions table
      $datetime = date('Y-m-d H:i:s');
      $data = array(
        'id'       => NULL,
        'version'  => $new_version,
        'created'  => $datetime,
        'updated'  => $datetime,
        'variants' => $this->db->count_all($new_live_table),
        'genes'    => count($genes),
      );
      $this->db->insert($this->tables['versions'], $data);
    }
*/
    $new_live_table = $this->tables['vd_live'];
    $new_queue_table = $this->tables['vd_queue'];
    $new_reviews_table = $this->tables['reviews'];
    $new_count_table = $this->tables['variant_count'];
    
    // Update the *new* live table with the new changes
    foreach ($new_records as $record) {
      $this->db->update($new_live_table, $record, 'id = ' . $record->id);
    }

    // Remove variants from the *new* live table that were scheduled for deletion
    foreach ($delete_records as $delete_record) {
      $this->db->delete($new_live_table, array('id' => $delete_record->variant_id));
      $this->db->delete($new_queue_table, array('id' => $delete_record->variant_id));
      $this->db->delete($new_reviews_table, array('variant_id' => $delete_record->variant_id));
    }

    // Get genes and associated variant counts, insert into new variant count table
    $this->load->model('genes_model');
    $genes = $this->genes_model->get_genes();
    foreach ($genes as $gene) {
      $variant_count = $this->db
                            ->get_where($new_live_table, array('gene' => $gene))
                            ->num_rows();
      $data = array(
        'id'    => NULL,
        'gene'  => $gene,
        'count' => $variant_count,
      );
      $this->db->insert($new_count_table, $data);
    }

    // Delete empty records from the new and previous live tables
    // --> if a record doesn't have a 'variation' or a 'hgvs_nucleotide_change' then it shouldn't be here
    $this->db->delete($this->tables['vd_live'], array('variation' => NULL, 'hgvs_nucleotide_change' => NULL));
    $this->db->delete($new_live_table, array('variation' => NULL, 'hgvs_nucleotide_change' => NULL));

    // Delete all review information and queue data for ONLY the records
    // that were released
    $delete_records = $new_records;
    foreach ($delete_records as $delete_record) {
      $this->db->delete($new_queue_table, array('id' => $delete_record->id));
      $this->db->delete($new_reviews_table, array('variant_id' => $delete_record->id));
    }

    // Get new version number
    $new_version = ((int) $this->version);
    
    // Update versions table
    $datetime = date('Y-m-d H:i:s');
    $data = array(
      'id'       => NULL,
      'version'  => $new_version,
      'created'  => $datetime,
      'updated'  => $datetime,
      'variants' => $this->db->count_all($new_live_table),
      'genes'    => count($genes),
    );
    $this->db->insert($this->tables['versions'], $data);
    $new_version_id = $this->db->query("SELECT MAX(id) FROM versions");

/*    // Delete any intial import data/tables (they aren't needed anymore)
    // NOTE: initial import data is equal to Version 0
    $initial_live = $this->variations_model->get_new_version_name($this->tables['vd_live'], -1); // i.e. "variations_0"
    if ($this->db->table_exists($initial_live)) {
      $this->load->dbforge();
      // Drop initial live table
      $this->dbforge->drop_table($initial_live);
      // Drop initial queue table
      $initial_queue = $this->variations_model->get_new_version_name($this->tables['vd_queue'], -1); // i.e. "variations_queue_0"
      $this->dbforge->drop_table($initial_queue);
      // Drop variant count table
      $initial_count = $this->variations_model->get_new_version_name($this->tables['variant_count'], -1); // i.e. "variant_count_0"
      $this->dbforge->drop_table($initial_count);
      // Drop reviews table
      $initial_reviews = $this->variations_model->get_new_version_name($this->tables['reviews'], -1); // i.e. "reviews_0"
      $this->dbforge->drop_table($initial_reviews);
      // Delete version 0 from the versions table
      $this->db->delete($this->tables['versions'], array('version' => 0)); 
    }
*/
    // Log it!
    $username = $this->ion_auth->user()->row()->username;
    activity_log("User '$username' released a new version of the database -- Version $new_version_id", 'release');
    
    return TRUE;
  }

  /**
   * Update Variant Review Info
   *
   * Updates all of the review information for the variant.
   * Review info is for staff use only and is never displayed
   * to the public.
   *
   * @author  Sean Ephraim
   * @access  public
   * @param   int    $variant_id Variant ID number
   * @param   array  $data Assoc. array of variant fields/values
   * @return  void
   */
  public function update_variant_review_info($variant_id, $data = array())
  {
    // Sanitize the data to be inserted
    // Remove fields that are not in this table (or are auto-incremeted)
    $table_fields = $this->db->list_fields($this->tables['reviews']);
    foreach ($data as $key => $value) {
      if (in_array($key, $table_fields) && $key !== 'id') {
        $clean_data[$key] = $value;
      }
    }
    // 'variant_id' must be specially mapped
    $clean_data['variant_id'] = $variant_id;

    // Set update time
    $datetime = date('Y-m-d H:i:s');
    $clean_data['updated'] = $datetime;

    $query = $this->db->get_where($this->tables['reviews'], array('variant_id' => $variant_id), 1);

    if ($query->num_rows() > 0) {
      // Variant already has a review, update it!
      $this->db
           ->where('variant_id', $variant_id)
           ->update($this->tables['reviews'], $clean_data);
    }
    else {
      // Variant does NOT already have a review, create one!
      // Update versions table
      $clean_data['created'] = $datetime;
      $this->db
           ->insert($this->tables['reviews'], $clean_data);
    }
  }

  /**
   * Get All Variants
   *
   * Gets all the variant records in a table.
   *
   * @author Sean Ephraim
   * @access public
   * @return object  All variant data in a specific table
   */
  public function get_all_variants($table = NULL)
  {
    if ($table === NULL) {
      $table = $this->tables['vd_live'];
    }

    return $this->db->get($table)->result();
  }

  /**
   * Get Unreleased Changes
   *
   * For each variant in the queue, get all differences between the
   * unreleased queued data and the live data. Example output:
   *
   *     $variants[19]['id'] = 19
   *                  ['name'] = 'NM_012130:c.687G>A'
   *                  ['changes']['pubmed_id']['live_value']  = 123
   *                                          ['queue_value'] = 12345
   *                             ['lrt_score']['live_value']  = 0.992
   *                                          ['queue_value'] = 0.993
   *                  ['is_new'] = FALSE
   *              [46]['id']     = 46
   *                  ['name']   = 'NM_012130:c.690C>T'
   *                  ['changes']['pubmed_id']['live_value']  = NULL
   *                                          ['queue_value'] = 9876
   *                  ['is_new'] = FALSE
   *
   * Specify a variant ID in order to only get changes for that variant.
   * If no ID is specified, then unreleased changes for all variants are returned.
   *
   * @author Sean Ephraim
   * @access public
   * @param  int $variant_id Variant unique ID
   * @return mixed Array of unreleased changes; NULL if no changes exist
   */
  public function get_unreleased_changes($variant_id = NULL)
  {
    if ($variant_id !== NULL) {
      // Get single variant from queue
      $query = $this->variations_model->get_variant_by_id($variant_id);
      $queue_variants = array(); // This is necessary in order for the foreach loop to work
      if ($query) {
        $queue_variants[] = $query; 
      }
    }
    else {
      // Get all variants from queue
      $queue_variants = $this->variations_model->get_all_variants($this->tables['vd_queue']);
    }

    // Compare queue values to live values
    $variants = array();
    if (is_array($queue_variants)) {
      foreach ($queue_variants as $queue_variant) {
        $queue_variant = (array) $queue_variant;
        $id = $queue_variant['id'];
        $live_variant = (array) $this->variations_model->get_variant_by_id($id, $this->tables['vd_live']); 
  
        $review = $this->variations_model->get_variant_review_info($id);
  
        // Create an array of variant changes
        if ($queue_variant !== $live_variant || !empty($review)) {
          $variants[$id]['id'] = $id;
          $variants[$id]['changes'] = array();
          $variants[$id]['name'] = $queue_variant['gene'] . ' <i class="icon-stop"></i> ' . $queue_variant['hgvs_protein_change'] . ' <i class="icon-stop"></i> ' . $queue_variant['variation'];
  
          // Check if variation is already in the live and/or queue database 
          // --> assign 'new variant' label accordingly
          $query_live = $this->db->get_where($this->tables['vd_live'], array('id' => $queue_variant['id'], 'variation' => NULL), 1);
          if ($query_live->num_rows() > 0) {
            $variants[$id]['is_new'] = TRUE;
          }
          else {
            $variants[$id]['is_new'] = FALSE;
          }
  
          foreach ($queue_variant as $field => $value) {
            // Identify changed fields
            if ( ! array_key_exists($field, $live_variant) || $queue_variant[$field] !== $live_variant[$field]) {
              $variants[$id]['changes'][$field]['queue_value'] = $queue_variant[$field];
    
              if ($variants[$id]['is_new'] || ! array_key_exists($field, $live_variant)) {
                // If this is a new variant, then the 'live_value' should be NA
                $variants[$id]['changes'][$field]['live_value'] = '<i>None</i>';
  
                // If the queue value and live value are empty, then disregard this field altogether
                if ($variants[$id]['changes'][$field]['queue_value'] === NULL || $variants[$id]['changes'][$field]['queue_value'] === '') {
                  unset($variants[$id]['changes'][$field]);
                }
              }
              else {
                // If this variant already exists in the DB, then use its current 'live_value'
                $variants[$id]['changes'][$field]['live_value'] = $live_variant[$field];
  
                if ($variants[$id]['changes'][$field]['queue_value'] === NULL || $variants[$id]['changes'][$field]['queue_value'] === '') {
                  // If the queue value and live value are empty, then disregard this field altogether
                  if ($variants[$id]['changes'][$field]['live_value'] === NULL || $variants[$id]['changes'][$field]['live_value'] === '') {
                    unset($variants[$id]['changes'][$field]);
                  }
                  else {
                    // If queue value is empty, then display 'None'
                    $variants[$id]['changes'][$field]['queue_value'] = '<i>None</i>';
                  }
                }
                // If live value is empty, then display 'None'
                if (isset($variants[$id]['changes'][$field])) {
                  if ($variants[$id]['changes'][$field]['live_value'] === NULL || $variants[$id]['changes'][$field]['live_value'] === '') {
                    $variants[$id]['changes'][$field]['live_value'] = '<i>None</i>';
                  }
                }
              }
            }
          }
        }
      } // end foreach
    } // end if

    // Add variants that don't have any changes but (a.) are scheduled for deletion,
    // or (b.) have comments for the informatics team
    if ($variant_id !== NULL) {
      $review = $this->variations_model->get_variant_review_info($variant_id);
      if (empty($review)) {
        $reviews = array();
      }
      else {
        $reviews = array($review);
      }
    }
    else {
      $reviews = $this->variations_model->get_variant_reviews();
    }
    if (is_array($reviews)) {
      foreach ($reviews as $review) {
        $id = $review->variant_id;
        $comments = $review->informatics_comments;
        $delete = $review->scheduled_for_deletion;
        if ( ! array_key_exists($id, $variants)) {
          if (!empty($comments) || $delete == 1) {
            $live_variant = (array) $this->variations_model->get_variant_by_id($id, $this->tables['vd_live']); 
            if ( ! empty($live_variant)) {
              $variants[$id]['id'] = $id;
              $variants[$id]['changes'] = array();
              $variants[$id]['name'] = $live_variant['gene'] . ' <i class="icon-stop"></i> ' . $live_variant['hgvs_protein_change'] . ' <i class="icon-stop"></i> ' . $live_variant['variation'];
              $variants[$id]['is_new'] = FALSE;
            }
          }
        }
      }
    }

    if (count($variants) == 0) {
      return NULL;
    }

    return $variants;
  }

  /**
   * Validate Variant ID
   *
   * @author Nikhil Anand
   * @param string $id Variant unique ID
   * @return void
   */
  public function _validate_variant_id($id) {
    if (!(preg_match('/[0-9]+/', $id))) {
      print "Invalid request";
      exit(9);
    } 
  }
  
  /**
   * Load Variant
   *
   * @author Nikhil Anand
   * @param  int $id  Variant unique ID
   * @return void
   */
  public function load_variant($id) {
      _validate_variant_id($id);
      return _api_search("id", array($id), $this->version);
  }
  
  /**
   * Get Letter Table
   *
   * Generate the table of gene letters users can click on. 
   * Any letter that doesn't have any genes associated (yet) will not have a hyperlink.
   * The links themselves are handled on the frontend (via AJAX).
   *
   * @author   Nikhil Anand
   * @author   Sean Ephraim
   * @return   string   HTML for gene letter table
   */
  public function get_letter_table($selected_letter = NULL) {
  
  	// Validate input param
  	if ($selected_letter != NULL) {
  		$selected_letter = $this->validate_gene_letter($selected_letter);
  	}
  
    // Ascertain which letters of the alphabet have genes associated with them
    $this->db->select('DISTINCT(LOWER(LEFT(gene,1))) AS val 
              FROM `' . $this->tables['vd_live'] . 
              '` ORDER BY gene', FALSE);
    $query = $this->db->get();
    $results = $query->result();

    foreach ($query->result() as $row) {
      $gene_letter = $row->val;
    
    	// Start building link HTML
    	$letter_uri = '<a ';
    
    	// Determine if a given letter is to be highlighted
    	if (strtoupper($gene_letter) == $selected_letter) {
    		$letter_uri .= ' class="active-letter" ';
    	}
    	
    	// Finish rest of link
    	$letter_uri .= ' href="'.base_url().'letter/'.$gene_letter.'">'.$gene_letter.'</a>';
    	
    	// Assign link to letter
    	$alphabet[$gene_letter] = $letter_uri;
  	
    } /* End while */
    
    // Make sure we show the 'inactive' letters as well (logic by Kyle Taylor!)
    for($i = 0; $i <= 26; $i++) {
        if (!isset($alphabet[chr($i+96)])) {
            $alphabet[chr($i+96)] = chr($i+96);
        }
    }
      
      // Draw the table
  	$output =<<<EOF
  	<table border="0" cellspacing="0" cellpadding="0">
  	  <tr>
  	    <td>{$alphabet["a"]}</td>
  	    <td>{$alphabet["b"]}</td>
  	    <td>{$alphabet["c"]}</td>
  	    <td>{$alphabet["d"]}</td>
  	    <td>{$alphabet["e"]}</td>
  	    <td>{$alphabet["f"]}</td>
  	    <td class="side-right">{$alphabet["g"]}</td>
  	  </tr>
  	  <tr>
  	    <td>{$alphabet["h"]}</td>
  	    <td>{$alphabet["i"]}</td>
  	    <td>{$alphabet["j"]}</td>
  	    <td>{$alphabet["k"]}</td>
  	    <td>{$alphabet["l"]}</td>
  	    <td>{$alphabet["m"]}</td>
  	    <td class="side-right">{$alphabet["n"]}</td>
  	  </tr>
  	  <tr>
  	    <td>{$alphabet["o"]}</td>
  	    <td>{$alphabet["p"]}</td>
  	    <td>{$alphabet["q"]}</td>
  	    <td>{$alphabet["r"]}</td>
  	    <td>{$alphabet["s"]}</td>
  	    <td>{$alphabet["t"]}</td>
  	    <td class="side-right">{$alphabet["u"]}</td>
  	  </tr>
  	  <tr>
  	    <td class="side-bottom">{$alphabet["v"]}</td>
  	    <td class="side-bottom">{$alphabet["w"]}</td>
  	    <td class="side-bottom">{$alphabet["x"]}</td>
  	    <td class="side-bottom">{$alphabet["y"]}</td>
  	    <td class="side-bottom">{$alphabet["z"]}</td>
  	    <td class="side-bottom"></td>
  	    <td class="side-right side-bottom"></td>
  	  </tr>
  	</table>
EOF;
  	return $output;
  }

  /**
<<<<<<< HEAD
   * Create a formatted table of variants for all genes starting with a given letter.
   *
   * @author Nikhil Anand
   * @author Zachary Ladlie
   * @access public
   * @param string $result 
   * 			An array of database results for a gene letter
   * @return void
   */
  public function format_variants_table(&$variant_info) {
    // Show the table opened if we have only one result
    $display   = "display:none;";
    $collapsed = "";
    if (sizeof($variant_info) == 1) {
      $display = "";
      $collapsed = "collapsed";
    }
    
    $table = '';

    foreach ($variant_info as $gene => $mutations) {

      // Build CSV, Tab-delimited, JSON and XML links
      $uri_str = site_url("api?type=gene&amp;terms=$gene&amp;format=");
      $uri_csv = $uri_str  . 'csv';
      $uri_tab = $uri_str  . 'tab';
      $uri_jsn = $uri_str  . 'json';
      $uri_xml = $uri_str  . 'xml';
        
      // Fieldset containing gene name and table header
      $table .=<<<EOF
      \n
      <fieldset>
          <legend class="genename $collapsed" id="$gene"><strong>$gene</strong> <span><a href="$uri_csv">CSV</a> <a href="$uri_tab">Tab</a> <a href="$uri_jsn">JSON</a> <a href="$uri_xml">XML</a></span></legend>
          <div id="table-$gene" style="$display">
              <table class="gene-table">
              <thead>
                  <tr>
                      <th class="header-link">&nbsp;</th>
                      <th class="top-border header-protein">HGVS protein change</th>
                      <th class="top-border header-nucleotide">HGVS nucleotide change</th>
                      <th class="top-border header-locale">Variant Locale</th>
                      <th class="top-border header-position">Genomic position (Hg19)</th>
                      <th class="top-border header-variant">Variant Type</th>
                      <th class="top-border header-disease">Phenotype</th>
                  </tr>
              </thead>
              <tbody>\n
EOF;

        // Rows of each table
        $zebra = '';
        foreach ($mutations as $mutation) {
            
            // To zebra stripe rows
            $zebra = ( 'odd' != $zebra ) ? 'odd' : 'even';

            $id                      = $mutation["id"];
            if (empty($mutation["hgvs_protein_change"])) {
              $hgvs_protein_change   = "&nbsp;"; // Avoid HTML errors
            }
            else {
              $hgvs_protein_change   = wordwrap($mutation["hgvs_protein_change"], 30, '<br />', 1);
            }
            $hgvs_nucleotide_change  = wordwrap($mutation["hgvs_nucleotide_change"], 25, '<br />', 1);
            $variantlocale           = $mutation["variantlocale"];
            $variation               = wordwrap($mutation["variation"], 25, '<br />', 1);
            $pathogenicity           = $mutation["pathogenicity"];
            $disease                 = $mutation["disease"];
            $variant_link            = site_url('variant/' . $id . '?full');    

            // Change the text of the variant type
            if(strcmp($pathogenicity, "vus") == 0) {
              $pathogenicity = '<span class="unknown_disease">Unknown significance</span>';
            } else if(strcmp($pathogenicity, "probable-pathogenic") == 0) {
              $pathogenicity = '<span class="probably_pathogenic">Probably Pathogenic</span>';
            } else if(strcmp($pathogenicity, "Pathogenic") == 0) {
              $pathogenicity = '<span class="pathogenic">Pathogenic</span>';
            }

            // Start drawing rows
            $table .=<<<EOF
                <tr class="$zebra showinfo" id="mutation-$id">
                    <td class="external-link"><a href="$variant_link"><span>More Information &raquo;</span></a></td>
                    <td class="showinfo-popup"><a><code>$hgvs_protein_change</code></a></td>
                    <td class="showinfo-popup"><code>$hgvs_nucleotide_change</code></td>
                    <td class="showinfo-popup">$variantlocale</td>
                    <td class="showinfo-popup"><code>$variation</code></td>
                    <td class="showinfo-popup">$pathogenicity</td>
                    <td class="showinfo-popup">$disease</td>
                </tr>
EOF;
        }

        // Finish table
        $table .= <<<EOF
                </tbody>
                </table>
            </div>
        </fieldset>
EOF;
    }
    
    return $table;
  }

  /**
   * Load all information in the variants table for all genes starting with a given letter.
   *
   * By default, all variants are displayed. If the second parameter ($show_unknown) is
   * set to FALSE, then the variants labeled with "Unknown significance" will not be shown.
   *
   * @author Nikhil Anand
   * @author Sean Ephraim
   * @author Zachary Ladlie
   * @param  string $letter First letter of gene
   * @param  boolean $show_unknown Show/hide unknown variants
   * @return array  Variation data to be displayed genes page
   */
  public function load_gene($letter, $show_unknown = TRUE) {
      
        $counter = 0;
        $result = '';
      
        // Sanitize in case the invoker doesn't
        $letter = $this->validate_gene_letter($letter);

        // Construct and run query
        if ($letter == '') {
            $query = "SELECT * FROM `" . $this->tables['vd_live'] . "` ORDER BY gene ASC;";
        }
        elseif ($show_unknown) {
            $query = sprintf('SELECT * FROM `%s` WHERE gene LIKE \'%s%%\' ORDER BY gene ASC', $this->tables['vd_live'], $letter);
        }
        else {
            $query = sprintf('SELECT * FROM `%s` WHERE gene LIKE \'%s%%\' AND pathogenicity != "Unknown significance" ORDER BY gene ASC', $this->tables['vd_live'], $letter);
        }
        $query_result = mysql_query($query);
      
        // Build array of results. Group all by gene. 
        $current_gene = '';
        while ($mutation = mysql_fetch_assoc($query_result)) {
            
            // Make sure the multi array is indexed 0, 1, 2 etc for EACH gene
            if ($current_gene != $mutation["gene"]) {
                $counter = 0;
            }
  
            $result[$mutation["gene"]][$counter]["id"] = $mutation["id"];
            $result[$mutation["gene"]][$counter]["hgvs_protein_change"] = $mutation["hgvs_protein_change"];
            $result[$mutation["gene"]][$counter]["hgvs_nucleotide_change"] = $mutation["hgvs_nucleotide_change"];
            $result[$mutation["gene"]][$counter]["variantlocale"] = $mutation["variantlocale"];
            $result[$mutation["gene"]][$counter]["variation"] = $mutation["variation"];
            $result[$mutation["gene"]][$counter]["pathogenicity"] = $mutation["pathogenicity"];
            $result[$mutation["gene"]][$counter]["disease"] = $mutation["disease"];
            
            $current_gene = $mutation["gene"];
            $counter++;
        }
        return $result;
  }

  /**
   * Validate Gene Name
   *
   * @author Sean Ephraim
   * @param  string $name Name of gene
   * @return string Name of gene (sanitized)
   */
  public function validate_gene_name($name) {
    if (!(preg_match('/[A-Z]{1}/', $name))) {
      print "Invalid request for name ".$name;
      exit(8);
    }
    return $name;
  }

  /**
   * Validate Gene Letter
   *
   * @author Nikhil Anand (modified by Zachary Ladlie)
   * @param  string $letter Letter of gene
   * @return string Letter of gene (sanitized)
   */
  public function validate_gene_letter($letter) {
    
    $letter = strtoupper(substr(trim($letter),0,1));
    
    if (!(preg_match('/[A-Z]{1}/', $letter))) {
      print "Invalid request for letter ".$letter;
      exit(8);
    }
    return $letter;
  }

  /**
   * Get Variant Display Variables
   *
   * Get the variables for the public view of a variant.
   *
   * @author Nikhil Anand
   * @author Sean Ephraim
   * @param  int $id Variant unique ID
   * @param  string $table Table to query from
   * @return array  Data variables to load into the view
   */
  public function get_variant_display_variables($id, $table = NULL) {
    // Default table is the queue
    if ($table === NULL) {
      $table = $this->tables['vd_live'];
    }

    // Load variant data
    $id = trim($id);
    $variant = $this->get_variant_by_id($id, $table);
    $freqs = $this->config->item('frequencies'); // frequencies to display

    // Make variables out of array keys. Variable variables are AWESOME!
    foreach ($variant as $key => $value) {
      $data[$key] = $value;
    }

    // These can get long
    $data['hgvs_protein_change']    = wordwrap($data['hgvs_protein_change'], 30, '<br />', 1);
    $data['hgvs_nucleotide_change'] = wordwrap($data['hgvs_nucleotide_change'], 25, '<br />', 1);
    $data['variation']              = wordwrap($data['variation'], 25, '<br />', 1);
    
    // Pubmed, dbSNP IDs, comments
    if (trim($data['pubmed_id']) == NULL) {
      $data['link_pubmed'] = "<span>(no data)</span>";
    } 
    else {
      $pubmed_url = "http://www.ncbi.nlm.nih.gov/pubmed/";
      $data['link_pubmed'] = '<a href="'.$pubmed_url.$data['pubmed_id'].'" title="Link to Pubmed">'.$data['pubmed_id'].'</a>';
    }
    if (trim($data['dbsnp']) == NULL) {
      $data['link_dbsnp'] = "<span>(no data)</span>";
    } 
    else {
      $amp = '&amp;'; // must use this in hrefs instead of '&' to avoid warnings
      $dbsnp_url = "http://www.ncbi.nlm.nih.gov/projects/SNP/snp_ref.cgi?searchType=adhoc_search&amp;type=rs&amp;rs=";
      $data['link_dbsnp'] = '<a href="'.$dbsnp_url.$data['dbsnp'].'" title="Link to dbSNP page">'.$data['dbsnp'].'</a>';
    }
    if (trim($data['comments']) == NULL) {
      $data['comments'] = "<span>(no data)</span>";
    } 
    
    // phyloP
    if (is_numeric($data['phylop_score'])) {
      // Conservation threshold
      if ($data['phylop_score'] > 1) {
        $data['class_phylop'] = "red";
        $data['desc_phylop'] = "Conserved";
      } 
      else {
        $data['class_phylop'] = "green";
        $data['desc_phylop'] = "Non-conserved";    
      }
    }
    else {
      $data['class_phylop'] = "gray";
      $data['desc_phylop'] = "Unknown";
    }

    // GERP++
    if (is_numeric($data['gerp_rs'])) {
      // Conservation threshold
      if ($data['gerp_rs'] > 0) {
        $data['class_gerp'] = "red";
        $data['desc_gerp'] = "Conserved";
      } 
      else {
        $data['class_gerp'] = "green";
        $data['desc_gerp'] = "Non-conserved";    
      }
    }
    else {
      $data['class_gerp'] = "gray";
      $data['desc_gerp'] = "Unknown";
    }

    // SIFT
    if (is_numeric($data['sift_score'])) {
      // Damage threshold
      if ($data['sift_score'] < 0.05) {
        $data['class_sift'] = "red";
        $data['desc_sift'] = "Damaging";
      } 
      else {
        $data['class_sift'] = "green";
        $data['desc_sift'] = "Tolerated";    
      }
    }
    else {
      $data['class_sift'] = "gray";
      $data['desc_sift'] = "Unknown";
    }
    
    // PolyPhen2
    if (stristr($data['polyphen2_pred'], "D") !== FALSE) {
      $data['class_polyphen'] = "red";
      $data['desc_polyphen'] = "Probably Damaging";
    } elseif (stristr($data['polyphen2_pred'], "P") !== FALSE) {
      $data['class_polyphen'] = "orange";
      $data['desc_polyphen'] = "Possibly Damaging";
    } elseif (stristr($data['polyphen2_pred'], "B") !== FALSE) {
      $data['class_polyphen'] = "green";
      $data['desc_polyphen'] = "Benign";    
    } else {
      $data['class_polyphen'] = "gray";
      $data['desc_polyphen'] = "Unknown";
    }
    
    // LRT
    if (stristr($data['lrt_pred'], "D") !== FALSE) {
      $data['class_lrt'] = "red";
      $data['desc_lrt'] = "Deleterious";
    } elseif (stristr($data['lrt_pred'], "N") !== FALSE) {
      $data['class_lrt'] = "green";
      $data['desc_lrt'] = "Neutral";
    } else {
      $data['class_lrt'] = "gray";
      $data['desc_lrt'] = "Unknown";    
    }
    
    // MutationTaster
    if (stristr($data['mutationtaster_pred'], "D") !== FALSE) {
      $data['class_mutationtaster'] = "red";
      $data['desc_mutationtaster'] = "Disease Causing";
    } elseif (stristr($data['mutationtaster_pred'], "A") !== FALSE) {
      $data['class_mutationtaster'] = "red";
      $data['desc_mutationtaster'] = "Disease Causing (Automatic)";
    } elseif (stristr($data['mutationtaster_pred'], "N") !== FALSE) {
      $data['class_mutationtaster'] = "green";
      $data['desc_mutationtaster'] = "Polymorphism";
    } elseif (stristr($data['mutationtaster_pred'], "P") !== FALSE) {
      $data['class_mutationtaster'] = "green";
      $data['desc_mutationtaster'] = "Polymorphism (Automatic)";    
    } else {
      $data['class_mutationtaster'] = "gray";
      $data['desc_mutationtaster'] = "Unknown";    
    }

    // Which frequency data to show, if any?
    $data['disp_freqs'] = (count($freqs) > 0) ? 'block' : 'none';
    $data['disp_evs'] = in_array('evs', $freqs) ? 'block' : 'none';
    $data['disp_1000g'] = in_array('1000genomes', $freqs) ? 'block' : 'none';
    $data['disp_otoscope'] = in_array('otoscope', $freqs) ? 'block' : 'none';
    
    // Frequency computations
    $zero_label = 'Unseen (0.000)'; // What to display when 0 alleles are seen
    if (in_array('otoscope', $freqs)) {
      // Display OtoSCOPE
      ($data['otoscope_aj_af'] == '')  ? $data['otoscope_aj_label']  = '(No data)' : ($data['otoscope_aj_af']  == 0) ? $data['otoscope_aj_label']  = $zero_label : $data['otoscope_aj_label']  = $data['otoscope_aj_ac']  . "/" . 400  . " (" . number_format((float) $data['otoscope_aj_af'],  3, '.', '') . ")";
      ($data['otoscope_co_af'] == '')  ? $data['otoscope_co_label']  = '(No data)' : ($data['otoscope_co_af']  == 0) ? $data['otoscope_co_label']  = $zero_label : $data['otoscope_co_label']  = $data['otoscope_co_ac']  . "/" . 320  . " (" . number_format((float) $data['otoscope_co_af'],  3, '.', '') . ")";
      ($data['otoscope_us_af'] == '')  ? $data['otoscope_us_label']  = '(No data)' : ($data['otoscope_us_af']  == 0) ? $data['otoscope_us_label']  = $zero_label : $data['otoscope_us_label']  = $data['otoscope_us_ac']  . "/" . 320  . " (" . number_format((float) $data['otoscope_us_af'],  3, '.', '') . ")";
      ($data['otoscope_jp_af'] == '')  ? $data['otoscope_jp_label']  = '(No data)' : ($data['otoscope_jp_af']  == 0) ? $data['otoscope_jp_label']  = $zero_label : $data['otoscope_jp_label']  = $data['otoscope_jp_ac']  . "/" . 400  . " (" . number_format((float) $data['otoscope_jp_af'],  3, '.', '') . ")";
      ($data['otoscope_es_af'] == '')  ? $data['otoscope_es_label']  = '(No data)' : ($data['otoscope_es_af']  == 0) ? $data['otoscope_es_label']  = $zero_label : $data['otoscope_es_label']  = $data['otoscope_es_ac']  . "/" . 360  . " (" . number_format((float) $data['otoscope_es_af'],  3, '.', '') . ")";
      ($data['otoscope_tr_af'] == '')  ? $data['otoscope_tr_label']  = '(No data)' : ($data['otoscope_tr_af']  == 0) ? $data['otoscope_tr_label']  = $zero_label : $data['otoscope_tr_label']  = $data['otoscope_tr_ac']  . "/" . 200  . " (" . number_format((float) $data['otoscope_tr_af'],  3, '.', '') . ")";
      ($data['otoscope_all_af'] == '') ? $data['otoscope_all_label'] = '(No data)' : ($data['otoscope_all_af'] == 0) ? $data['otoscope_all_label'] = $zero_label : $data['otoscope_all_label'] = $data['otoscope_all_ac'] . "/" . 2000 . " (" . number_format((float) $data['otoscope_all_af'], 3, '.', '') . ")";
    }
    else {
      // Don't display OtoSCOPE
      $data['otoscope_aj_af']  = 0;
      $data['otoscope_co_af']  = 0;
      $data['otoscope_us_af']  = 0;
      $data['otoscope_jp_af']  = 0;
      $data['otoscope_es_af']  = 0;
      $data['otoscope_tr_af']  = 0;
      $data['otoscope_all_af'] = 0;
      $data['otoscope_aj_label']  = '(No data)';
      $data['otoscope_co_label']  = '(No data)';
      $data['otoscope_us_label']  = '(No data)'; 
      $data['otoscope_jp_label']  = '(No data)'; 
      $data['otoscope_es_label']  = '(No data)'; 
      $data['otoscope_tr_label']  = '(No data)'; 
      $data['otoscope_all_label'] = '(No data)';
    }
    if (in_array('evs', $freqs)) {
      // Display EVS
      ($data['evs_ea_af'] == '')  ? $data['evs_ea_label']  = '(No data)' : ($data['evs_ea_af']  == 0) ? $data['evs_ea_label']  = $zero_label : $data['evs_ea_label']  = $data['evs_ea_ac']  . "/" . intval($data['evs_ea_ac']/$data['evs_ea_af'])   . " (" . number_format((float) $data['evs_ea_af'],  3, '.', '') . ")";
      ($data['evs_aa_af'] == '')  ? $data['evs_aa_label']  = '(No data)' : ($data['evs_aa_af']  == 0) ? $data['evs_aa_label']  = $zero_label : $data['evs_aa_label']  = $data['evs_aa_ac']  . "/" . intval($data['evs_aa_ac']/$data['evs_aa_af'])   . " (" . number_format((float) $data['evs_aa_af'],  3, '.', '') . ")";
      ($data['evs_all_af'] == '') ? $data['evs_all_label'] = '(No data)' : ($data['evs_all_af'] == 0) ? $data['evs_all_label'] = $zero_label : $data['evs_all_label'] = $data['evs_all_ac'] . "/" . intval($data['evs_all_ac']/$data['evs_all_af']) . " (" . number_format((float) $data['evs_all_af'], 3, '.', '') . ")";
    }
    else {
      // Don't display EVS
      $data['evs_ea_af']  = 0;
      $data['evs_aa_af']  = 0;
      $data['evs_all_af'] = 0;
      $data['evs_ea_label']  = '(No data)';
      $data['evs_aa_label']  = '(No data)';
      $data['evs_all_label'] = '(No data)';

    }
    if (in_array('1000genomes', $freqs)) {
      // Display 1000 Genomes
      ($data['tg_afr_af'] == '') ? $data['tg_afr_label'] = '(No data)' : ($data['tg_afr_af'] == 0) ? $data['tg_afr_label'] = $zero_label : $data['tg_afr_label'] = $data['tg_afr_ac'] . "/" . intval($data['tg_afr_ac']/$data['tg_afr_af']) . " (" . number_format((float) $data['tg_afr_af'], 3, '.', '') . ")";
      ($data['tg_eur_af'] == '') ? $data['tg_eur_label'] = '(No data)' : ($data['tg_eur_af'] == 0) ? $data['tg_eur_label'] = $zero_label : $data['tg_eur_label'] = $data['tg_eur_ac'] . "/" . intval($data['tg_eur_ac']/$data['tg_eur_af']) . " (" . number_format((float) $data['tg_eur_af'], 3, '.', '') . ")";
      ($data['tg_amr_af'] == '') ? $data['tg_amr_label'] = '(No data)' : ($data['tg_amr_af'] == 0) ? $data['tg_amr_label'] = $zero_label : $data['tg_amr_label'] = $data['tg_amr_ac'] . "/" . intval($data['tg_amr_ac']/$data['tg_amr_af']) . " (" . number_format((float) $data['tg_amr_af'], 3, '.', '') . ")";
      ($data['tg_asn_af'] == '') ? $data['tg_asn_label'] = '(No data)' : ($data['tg_asn_af'] == 0) ? $data['tg_asn_label'] = $zero_label : $data['tg_asn_label'] = $data['tg_asn_ac'] . "/" . intval($data['tg_asn_ac']/$data['tg_asn_af']) . " (" . number_format((float) $data['tg_asn_af'], 3, '.', '') . ")";
      ($data['tg_all_af'] == '') ? $data['tg_all_label'] = '(No data)' : ($data['tg_all_af'] == 0) ? $data['tg_all_label'] = $zero_label : $data['tg_all_label'] = $data['tg_all_ac'] . "/" . intval($data['tg_all_ac']/$data['tg_all_af']) . " (" . number_format((float) $data['tg_all_af'], 3, '.', '') . ")";
    }
    else {
      // Don't display 1000 Genomes
      $data['tg_afr_af'] = 0;
      $data['tg_eur_af'] = 0;
      $data['tg_amr_af'] = 0;
      $data['tg_asn_af'] = 0;
      $data['tg_all_af'] = 0;
      $data['tg_afr_label'] = '(No data)';
      $data['tg_eur_label'] = '(No data)';
      $data['tg_amr_label'] = '(No data)';
      $data['tg_asn_label'] = '(No data)';
      $data['tg_all_label'] = '(No data)';
    }
    
    return $data;
  }

  /**
   * Num Unreleased
   *
   * Returns total number of variants with unreleased changes.
   *
   * @author Sean Ephraim
   * @return int 
   *    Number of unreleased changes
   */
  public function num_unreleased() {
    return $this->db->count_all($this->tables['reviews']);
  }

  /**
  * variant-CADI functions
  **/
  /**
  * Run Annotation Pipeline
  *
  * Takes the timestamp associated with this submit and the list
  * of genes submitted and runs a series of scripts to collect
  * and annotate variants associated with the genes submitted.
  *
  * @author Andrea Hallier
  * @input timeStamp, genesFile
  */
  public function run_annotation_pipeline($timeStamp, $genesFile){
    $this->load->database();
    $regionsFile = "/asap/cordova_pipeline/myregions".$timeStamp.".txt";
    $variantsFile = "/asap/cordova_pipeline/myvariants".$timeStamp.".txt";
    $mapFile = "/asap/cordova_pipeline/myvariants.map".$timeStamp.".txt";
    $listFile = "/asap/cordova_pipeline/myvariants.list".$timeStamp.".txt";
    $kafeenFile = "/asap/cordova_pipeline/myvariants.kafeen".$timeStamp.".txt";
    $hgmd_clinvarFile = "/asap/cordova_pipeline/myvariants.hgmd_clinvar".$timeStamp.".txt";
    $f1File = "/asap/cordova_pipeline/myvariants.f1".$timeStamp.".txt";
    $f2File = "/asap/cordova_pipeline/myvariants.f2".$timeStamp.".txt";
    $f3File = "/asap/cordova_pipeline/myvariants.f3".$timeStamp.".txt";
    $f4File = "/asap/cordova_pipeline/myvariants.f4".$timeStamp.".txt";
    $finalFile = "/asap/cordova_pipeline/myvariants.final".$timeStamp.".txt";
    $CWD='/asap/cordova_pipeline';
    $KAFEEN='/asap/kafeen';
    //$RUBY="/usr/local/rvm/rubies/ruby-2.1.5/bin/ruby";
    $RUBY = $this->config->item('ruby_path');
    $annotation_path = $this->config->item('annotation_path');
    $PATH = getenv('PATH');
    $vd_queue = $this->tables['vd_queue'];
    
    ini_set('memory_limit', '-1');
    set_time_limit(0);
    //exec("nohup sh -c '$RUBY /asap/cordova_pipeline/genes2regions.rb $genesFile &> $regionsFile && $RUBY /asap/cordova_pipeline/regions2variants.rb $regionsFile &> $variantsFile && $RUBY /asap/cordova_pipeline/map.rb $variantsFile &> $mapFile ; cut -f1 $mapFile>$listFile && $RUBY /asap/kafeen/kafeen.rb --progress -i $listFile -o $kafeenFile && $RUBY /asap/cordova_pipeline/annotate_with_hgmd_clinvar.rb $kafeenFile $mapFile &> $hgmd_clinvarFile && cut -f-6  $kafeenFile > $f1File && cut -f2-4 $hgmd_clinvarFile > $f2File && cut -f10- $kafeenFile > $f3File && paste $f1File $f2File > $f4File && paste $f4File $f3File > $finalFile' &");
    //exec("nohup sh -c '$RUBY /Shared/utilities/cordova_pipeline_v2/pipeline.rb $genesFile' &");
    //$op = system("$RUBY /Shared/utilities/cordova_pipeline_v2/pipeline.rb $genesFile &> outPutLog.txt",$returns);
    //exec("export PATH=$PATH:/Shared/utilities/vcftools_0.1.13/bin/");
    
    //exec("cd $annotation_path && export PATH=$PATH:/Shared/utilities/vcftools_0.1.13/bin/:/Shared/utilities/bin/ && $RUBY pipeline.rb $genesFile &> outPutLog$timeStamp.txt && gunzip /Shared/utilities/cordova_pipeline_v2/mygenes$timeStamp.vcf.gz && vcf-to-tab < /Shared/utilities/cordova_pipeline_v2/mygenes$timeStamp.vcf &> /Shared/utilities/cordova_pipeline_v2/mygenes$timeStamp.tab");

    //exec("cd $annotation_path && export PATH=$PATH:/Shared/utilities/vcftools_0.1.13/bin/:/Shared/utilities/bin/ && $RUBY pipeline.rb $genesFile &> outPutLog$timeStamp.txt && gunzip /Shared/utilities/cordova_pipeline_v2/mygenes$timeStamp.vcf.gz && vcftools --vcf mygenes$timeStamp.vcf --get-INFO ASAP_VARIANT --get-INFO GENE --get-INFO ASAP_HGVS_C --get-INFO ASAP_HGVS_P --get-INFO ASAP_LOCALE --get-INFO FINAL_PATHOGENICITY --get-INFO FINAL_DISEASE --out mygenes$timeStamp.tab &> vcftoolOUTPUT.txt && cut -f1,5- mygenes$timeStamp.tab.INFO -d'	' &> mygenes$timeStamp.final");
    
    $mysqlPassword = $this->db->password;
    $mysqlUser = $this->db->username;
    $queueTable = $this->tables['vd_queue'];
    $liveTable = $this->tables['vd_live'];
    $resultsTable = $this->tables['reviews'];
    $TSVfile = "$queueTable.tsv";
    $database = $this->db->database;
    $date = date("Y-m-d H:i:s"); 
   
    //GOING TO NEED TO MOVE THIS!!! NOT SAFE HERE!! MIGHT BREAK SOMETHING ELSE, IE IF NOT UNIUQE BEFORE NOW...
    $this->db->query("ALTER TABLE $queueTable ADD UNIQUE INDEX (`variation`)");
    
    //$COLUMNS=("dbsnp","evs_all_ac","evs_all_an","evs_all_af","evs_ea_ac","evs_ea_an","evs_ea_af","evs_aa_ac","evs_aa_an","evs_aa_af","tg_all_ac","tg_all_an","tg_all_af","tg_afr_ac","tg_afr_an","tg_afr_af","tg_amr_ac","tg_amr_an","tg_amr_af","tg_eas_ac","tg_eas_an","tg_eas_af","tg_eur_ac","tg_eur_an","tg_eur_af","tg_sas_ac","tg_sas_an","tg_sas_af","otoscope_all_ac","otoscope_all_an","otoscope_all_af","otoscope_aj_ac","otoscope_aj_an","otoscope_aj_af","otoscope_co_ac","otoscope_co_an","otoscope_co_af","otoscope_us_ac","otoscope_us_an","otoscope_us_af","otoscope_jp_ac","otoscope_jp_an","otoscope_jp_af","otoscope_es_ac","otoscope_es_an","otoscope_es_af","otoscope_tr_ac","otoscope_tr_an","otoscope_tr_af","gene","sift_score","sift_pred","polyphen2_score","polyphen2_pred","lrt_score","lrt_pred,mutationtaster_score,mutationtaster_pred,gerp_rs,phylop_score,gerp_pred,phylop_pred,variation,hgvs_nucleotide_change,hgvs_protein_change,variantlocale,pathogenicity,disease,pubmed_id,comments,exac_afr_ac,exac_afr_an,exac_afr_af,exac_amr_ac,exac_amr_an,exac_amr_af,exac_eas_ac,exac_eas_an,exac_eas_af,exac_fin_ac,exac_fin_an,exac_fin_af,exac_nfe_ac,exac_nfe_an,exac_nfe_af,exac_oth_ac,exac_oth_an,exac_oth_af,exac_sas_ac,exac_sas_an,exac_sas_af,exac_all_ac,exac_all_an,exac_all_af");
    $COLUMNS = "(id,gene,sift_score,sift_pred,polyphen2_score,polyphen2_pred,lrt_score,lrt_pred,mutationtaster_score,mutationtaster_pred,gerp_rs,phylop_score,gerp_pred,phylop_pred,variation,hgvs_nucleotide_change,hgvs_protein_change,variantlocale,pathogenicity,disease,pubmed_id,comments,dbsnp,evs_all_af,evs_ea_ac,evs_ea_af,evs_aa_ac,evs_aa_an,evs_aa_af,tg_all_af,tg_afr_af,tg_amr_af,tg_eur_af)";
    exec("cd $annotation_path && export PATH=$PATH:/Shared/utilities/vcftools_0.1.13/bin/:/Shared/utilities/bin/ && $RUBY pipeline.rb $genesFile &> outPutLog$timeStamp.txt && gunzip mygenes$timeStamp.vcf.gz && bash convert_Cordova_VCF_to_mysqlimport_TSV.sh mygenes$timeStamp.vcf &> mygenes$timeStamp.final && cp mygenes$timeStamp.final $queueTable.tsv && cut -f 0-32 $queueTable.tsv > $queueTable.tsvcleaned");
    exec("cp /Shared/utilities/cordova_pipeline_v2/outPutLog$timeStamp.txt /var/www/html/cordova_sites_ah/rdvd/tmp/myvariants$timeStamp.log");
    
    $file = fopen("$annotation_path/$queueTable.tsvcleaned", "r");
    //$numLines = count(file("$annotation_path/$queueTable.tsvcleaned"));
    $finalTsvPath = "$annotation_path/final$queueTable.tsv";
    exec("cp $finalTsvPath /var/www/html/cordova_sites_ah/rdvd/tmp/");
    $finalTsv = fopen($finalTsvPath, 'w'); 
    //get max id from queuei
    $maxid = 0;
    $row = $this->db->query("SELECT MAX(id) AS `maxid` FROM $liveTable")->row();
    if ($row) {
      #get max id from current queueTable
      $maxid = $row->maxid; 
      #Increment the id
      $maxid = $maxid+1;
    }
    #else the max id = 0
    $i = $maxid;
    //for each entry in tsv, add temp id
    while($line = fgets($file)){
      $data = explode("\t", $line);
      #encode the disease name, prone to incompatable characters
      if(isset($data[18])){
        $data[18] = urlencode($data[18]);
      }
      $dataString = implode("\t",$data);
      $newline = "$i"."\t"."$dataString";
      fwrite($finalTsv,$newline);
      $i=$i+1;
    }
    #chmod($finalTsvPath,0777);
    //mysql import tsv with replace on id and variation
    //$this->db->query("DELETE * FROM $queueTable");
    //$this->db->query("DELETE * FROM $liveTable");
    //$this->db->query("DELETE * FROM $resultsTable");
    $this->db->query("LOAD DATA LOCAL INFILE '".$finalTsvPath."' 
        REPLACE INTO TABLE $queueTable 
        FIELDS TERMINATED BY '\t'
        LINES TERMINATED BY '\\n' 
        IGNORE 1 LINES
        $COLUMNS");
    //does not allow duplicate entries, deletes old reccord and inserts new one
    //join with live table on variation and update ids in queue
    //could speed this up by only querrying the new variants in queue, ie id>maxid or id=null
    //maybe create a view?? to hold the newest variants in queue
    $query1 = $this->db->query("update $queueTable u
            inner join $liveTable s on
            u.variation = s.variation
            set u.id = s.id");

    //insert ids(autogenerated) and variant into live table where queue id is greater than maxid
    //this should be fine,, because there is no gene name associated with the variant in the live table
    $query2 = $this->db->query("INSERT INTO $liveTable (variation) SELECT variation FROM $queueTable WHERE id>=$maxid");

    //Reindex id's in queue
    $query3 = $this->db->query("update $queueTable u
              inner join $liveTable s on
              u.variation = s.variation
              set u.id = s.id");

    //insert ids and dates into reviews where queue id is greater than maxid
    //There is a problem here, this only inserts restuls when id>max id, needs to be when case is in queue and not in results...
    //This works when not re-entering exsisting genes.
    $query4 = $this->db->query("INSERT INTO $resultsTable (variant_id,created) SELECT id,'$date' FROM $queueTable WHERE id NOT IN(SELECT variant_id FROM $resultsTable)");
    
    //exec("touch /var/www/html/cordova_sites_ah/rdvd/tmp/queue.csv && chmod 777 /var/www/html/cordova_sites_ah/rdvd/tmp/queue.csv");
    $query5 = $this->db->query("SELECT * from $queueTable INTO OUTFILE '/tmp/queue$timeStamp.csv' FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\n';");
    exec("cp /tmp/queue$timeStamp.csv /var/www/html/cordova_sites_ah/rdvd/tmp/queue$timeStamp.csv");



    //Get id's from reviews where variation matches, ie it has been replaced and exsists in review and is null in live
    
    /*
    $query4 = $this->db->query("update $queueTable u
        inner join $resultsTable s on
        u.variation = s.variation
        set u.id = s.id"); 
    //update reviews records where variant exsists in queue, these will have id<max id and will not be updated later...
    
    $query5 = $this->db->query("update $resultsTable u
        inner join $queueTable s on
        u.variation = s.variation
        set u.created = '$date' , u.confirmed_for_release = 0 , u.scheduled_for_deletion = 0"); 

    //get table of all queue variants with id > maxid
    $query2 = $this->db->query("SELECT id FROM $queueTable WHERE id>$maxid");
    //do a join with the live table to update queue ids with live table ids if variant is already in live table
    $query3 = $this->db->query("update $queueTable u
        inner join $liveTable s on
        u.variation = s.variation
        set u.id = s.id"); 
    //get number of variants added to queue that were not in live table
    //$query1 = $this->db->query("SELECT id FROM $queueTable WHERE id NOT IN(SELECT id from $resultsTable) AND id NOT IN(SELECT id fromo $liveTable))");
    $numVariantsAdded = $query2->num_rows();
    //return $numVariantsAdded;
    
    //generate null file
//    $nullFilePath = "$annotation_path/nullFile$date.tsv";
//    for ($i = 0; $i < $numVariantsAdded; $i++) {
//      file_put_contents($nullFilePath, "null");
//    }
    //add that number of null entries to live table and assign auto generated ids to queue variants
    
//    $this->db->query("LOAD DATA LOCAL INFILE '".$nullFilePath."' 
//        INTO TABLE $liveTable 
//        FIELDS TERMINATED BY '\t'
//        LINES TERMINATED BY '\\n' 
//        (gene)");

    $keys = $this->get_variant_fields($liveTable);
    $null_data = array_fill_keys($keys, NULL); // set all values to NULL
    for ($i = 0; $i < $numVariantsAdded; $i++) {
      $this->db->insert($liveTable, $null_data);
    }
    //update new queue entries with new ids
    $query2 = $this->db->query("SELECT id FROM $queueTable WHERE id>$maxid");
    $i = $maxid + 1;
    if ($query2->num_rows() > 0){
      foreach ($query2->result() as $row){
        #$sql = "insert into $resultstable (variant_id, created) values ('$row[id]','$date')";
        $sql = "Update $queueTable set id = $i Where id = $row->id";
        $this->db->query($sql);
        $i = $i+1;
      }
    }
    $query6 = $this->db->query("INSERT INTO $resultsTable (variant_id) SELECT id FROM $queueTable WHERE id>$maxid");

    $sql = "Update $resultsTable set created = '$date' Where id > $maxid";
    $this->db->query($sql);
*/
    //add these new entries to results table

    #$maxid = 0;
    #$row = $this->db->query("SELECT MAX(id) AS `maxid` FROM $queueTable")->row();
    #if ($row) {
      #get max id from current queueTable
      #$maxid = $row->maxid; 
      #Increment the id
      #$maxid = $maxid+1;
    #}
    #else the max id = 0
    
    /*while($line = fgets($file)){
      $data = explode("\t", $line);
      $variation = '';
      if(isset($data[14])){
        $variation = $data[14];
      }
      $id = NULL;
      #encode the disease name, prone to incompatable characters
      if(isset($data[18])){
        $data[18] = urlencode($data[18]);
      }
      // Check if variation is already in the live and/or queue database
      $query_live  = $this->db->get_where($liveTable, array('variation' => $variation), 1);
      $query_queue = $this->db->get_where($queueTable, array('variation' => $variation), 1);
      //if variant is in live database
      if($query_live->num_rows() > 0){
        //set id, this is the id we want to use to update the variant  
        foreach ($query_live->result() as $row){
          $id = $row->id;
        }
      }
      //if variant is in queue database
      elseif($query_queue->num_rows() > 0){
        #for every entry in the queue, set up the results table
        foreach ($query_queue->result() as $row){
          //If id has not been set from live database
          if(!empty($id)){
            $id = $row->id;
          }
          //remove old queue entry
          $this->db->delete($queueTable, array('variation' => $variation));
          //remove old result entry
          $this->db->delete($resultsTable, array('variation' => $variation));
        }
      }
      //if not in any database, get new auto_inc id
      else{
        // Create empty row in live table and get its unique ID
        $keys = $this->get_variant_fields($liveTable);
        $null_data = array_fill_keys($keys, NULL); // set all values to NULL
        $this->db->insert($liveTable, $null_data);
        $id = $this->db->insert_id();
      }
      //Build value string and insert into queue
      if(sizeof($data)>10){
        #add id to data column
        $entries = "'$id',";
        #create list from data array
        foreach($data as $entry){
          $entries = $entries."'".$entry."',"; 
        }
        #remove trailing comma from list
        $trimmedEntries = rtrim($entries, ",");
        $sql = "INSERT INTO $queueTable $COLUMNS VALUES ($trimmedEntries)";
        $this->db->query($sql);
      }
    }#end while
    
    $this->db->select('id');
    $query = $this->db->get("$queueTable");
    #for every entry in the queue, set up the results table
    if ($query->num_rows() > 0){
      foreach ($query->result() as $row){
        #$sql = "insert into $resultstable (variant_id, created) values ('$row[id]','$date')";
        $sql = "insert into $resultstable (variant_id, created) values ('$row->id','$date')";
        $this->db->query($sql);
      }
    }
    //$db['development']['password'] = 'bUb78Nf7';
    //$db['development']['database'] = 'rdvd_test2';
    
    //exec("mysqlimport -u root -p -L rdvd_test2 variations_1.tsv");
    */
    return $timeStamp;
  }
  /**
  * Get Disease Names
  *
  * Generates a new file from the pipeline output that removes
  * unaccepted characters in gene names. It returns a list of 
  * unique gene names found in the cleaned file.
  *
  * @author Andrea Hallier
  * @input oldFile, newFile
  */
  public function get_disease_names(){
    $queueTable = $this->tables['vd_queue'];
    $sql = "SELECT gene, disease FROM $queueTable group by gene, disease"; 
    $query = $this->db->query($sql);
    $result = $query->result();
    $diseaseNames = array();
    $annotation_path = $this->config->item('annotation_path');
    //NEED TO MAKE VAR FOR THIS PATH TO FIND ROOT OF SYSTEM
    $tmpDir = "/var/www/html/cordova_sites_ah/rdvd/tmp";
    $time_stamp = date("YmdHis");
    $queueOutFilePath = "$tmpDir/queueOutputPath$time_stamp.csv";
    $handle = fopen($queueOutFilePath, 'w') or die('Cannot open file:  '.$queueOutFilePath);
    $sql = "SELECT * FROM $queueTable INTO OUTFILE '$handle' FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\n'";
    $csvDiseasePath = "$tmpDir/csvDisease$time_stamp.csv";
    // Instantiate a new PHPExcel object
    //$this->load->file('third_party/PHPExcel.php');
    //$this->load->library('excel');
    //$this->load->library('PHPExcel/iofactory');
    //$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
    //$objPHPExcel = new PHPExcel();
    //$fileType = 'Excel5';
    //$fileName = "$annotation_path/testFile.xls";

    // Read the file
    //$objReader = PHPExcel_IOFactory::createReader($fileType);
    //$objPHPExcel = $objReader->load($fileName);

    //$objPHPExcel = PHPExcel_IOFactory::load($file);
    // Set the active Excel worksheet to sheet 0
    //$objPHPExcel->setActiveSheetIndex(0); 
    // Initialise the Excel row number
    
    $rowCount = 1; 
    // Iterate through each result from the SQL query in turn
    // We fetch each database result row into $row in turn
    //while($row = mysql_fetch_array($result)){ 
    //foreach($result as $row){  
      // Set cell An to the "name" column from the database (assuming you have a column called name)
      //    where n is the Excel row number (ie cell A1 in the first row)
      //$objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, $row->disease); 
      // Set cell Bn to the "age" column from the database (assuming you have a column called age)
      //    where n is the Excel row number (ie cell A1 in the first row)
      //$objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, $row->disease); 
      // Increment the Excel row counter
      //$rowCount++; 
    //} 
    // Instantiate a Writer to create an OfficeOpenXML Excel .xlsx file
    //$objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel); 
    //$objWriter = PHPExcel_IOFactory::createWriter($this->excel, 'Excel5');
    // Write the Excel file to filename some_excel_file.xlsx in the current directory
    //$objWriter->save("$tmpDir/xlsDisease$timeStamp.xlsx");
    //rename("$annotation_path/csvDisease$timeStamp.xlsx", "$tmpDir/DiseaseNomenclature$timeStamp.xlsx");
    $csvDisease = fopen($csvDiseasePath, "w");
    fwrite($csvDisease, "Gene, Current, New\n");
    foreach($query->result() as $row){
      if(strcmp($row->disease,"+")){
        array_push($diseaseNames, urlencode(urldecode($row->disease)));
        fwrite($csvDisease, "\"".$row->gene."\",\"".urldecode($row->disease)."\",\"NewName\"\n");
      }
    }
    $csvDiseaseDownloadPath = "http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/tmp/csvDisease$time_stamp.csv";
    $csvQueueDownloadPath = "http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/tmp/queueOutputPath$time_stamp.csv";
    //exec("cp $annotation_path/csvDisease$timeStamp.xlsx $tmpDir/csvDisease$timeStamp.xlsx");
    //exec("cp $annotation_path/csvDisease$timeStamp.xlsx $tmpDir/csvDisease$timeStamp.csv");
    $data = array('diseaseNames' => $diseaseNames,
                  'csvDiseasePath' => $csvDiseasePath,
                  'csvDiseaseDownloadPath' => $csvDiseaseDownloadPath,
                  'queueDownloadPath' => $csvQueueDownloadPath);

    return $data;
  }  
  public function build_disease_excel_file($timeStamp = 00000000){
    $queueTable = $this->tables['vd_queue'];
    $sql = "SELECT DISTINCT disease FROM $queueTable"; 
    $query = $this->db->query($sql);
    //$query->result()
    // Instantiate a new PHPExcel object
    $objPHPExcel = new PHPExcel(); 
    // Set the active Excel worksheet to sheet 0
    $objPHPExcel->setActiveSheetIndex(0); 
    // Initialise the Excel row number
    $rowCount = 1; 
    // Iterate through each result from the SQL query in turn
    // We fetch each database result row into $row in turn
    while($row = mysql_fetch_array($result)){ 
      // Set cell An to the "name" column from the database (assuming you have a column called name)
      //    where n is the Excel row number (ie cell A1 in the first row)
      $objPHPExcel->getActiveSheet()->SetCellValue('A'.$rowCount, $row['disease']); 
      // Set cell Bn to the "age" column from the database (assuming you have a column called age)
      //    where n is the Excel row number (ie cell A1 in the first row)
      $objPHPExcel->getActiveSheet()->SetCellValue('B'.$rowCount, $row['age']); 
      // Increment the Excel row counter
      $rowCount++; 
    } 
    // Instantiate a Writer to create an OfficeOpenXML Excel .xlsx file
    $objWriter = new PHPExcel_Writer_Excel2007($objPHPExcel); 
    // Write the Excel file to filename some_excel_file.xlsx in the current directory
    $annotation_path = $this->config->item('annotation_path');
    $objWriter->save("$annotation_path/disease_excel_file$timeStamp.xlsx"); 
  }
  public function OLD_get_disease_names($oldFile,$newFile){
    $file = fopen($oldFile, "r");
    $cleanedFile = fopen($newFile, "w");
    $diseaseNames = array();
    while($line = fgets($file)){
      $explodedOldLine = explode("\t", $line);
      //grab all uniqe disease names, minus the header line
      if(!empty($explodedOldLine[7]) && (strcmp($explodedOldLine[0], "id") !== 0)){
        $diseaseName = $explodedOldLine[7];
        //$cleanedDiseaseName = preg_replace("/[^A-Za-z0-9 ]/", '', $diseaseName);
        $encodedDiseaseName = urlencode(urldecode($diseaseName));
        //$encodedDiseaseName = $diseaseName;
        $explodedOldLine[7] = $encodedDiseaseName;
        array_push($diseaseNames, $encodedDiseaseName);
      }
      fwrite($cleanedFile, implode("\t", $explodedOldLine)."\n");
    }
    $uniqueDiseases = array_unique($diseaseNames);
    return $uniqueDiseases;
  }
  /**
  * Update Disease Names
  *
  * Takes the user input from the normralize nomenclature form, the list of 
  * unique diseases determeined prviously and file paths to create new files
  * and read from old files.The old file informations and the submitted names
  * are used to create a new updated file with new disease names.
  *
  * @author Andrea Hallier
  * @input POST, uniqueDiseases, nameUpdatesFile, oldFileLocation, newFileLocation
  */

  public function update_disease_names($_POST, $nameUpdatesFile,  $uniqueDiseases, $input_type_file = FALSE){
    $queueTable = $this->tables['vd_queue'];
    if($input_type_file == FALSE){
      foreach($uniqueDiseases as $disease){
        $string = str_replace(" ", "_", $disease);
        //$string = urlencode($disease);
        if($_POST[$string]){
          $newName = urlencode($_POST[$string]);
          $sql = "UPDATE $queueTable SET disease='$newName' WHERE disease='$disease'";
          $query = $this->db->query($sql);
        }
      } 
    }
    if($input_type_file == TRUE){
      $submittedNameUpdates = fopen($nameUpdatesFile, "r");
      $row = 1;
      while($line = fgets($submittedNameUpdates)){
        if($row != 1){
          $data = explode("\",\"", $line);
          $newName = urlencode(str_replace('"', "", $data[2]));
          $disease = urlencode($data[1]);
          $gene = str_replace('"', "", $data[0]);
          $sql = "UPDATE $queueTable SET disease='$newName' WHERE disease='$disease' and gene='$gene'";
          $query = $this->db->query($sql);
        }
        $row ++;
      }
    } 
    $timeStamp = date("Ymdhms");
    //$query5 = $this->db->query("SELECT * from $queueTable INTO OUTFILE '/tmp/queueNomenUpdates$timeStamp.csv' FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\n';");
    //exec("cp /tmp/queueNomenUpdates$timeStamp.csv /var/www/html/cordova_sites_ah/rdvd/tmp/queueNomenUpdates$timeStamp.csv");
    return $query;
  } 
  
  
  public function OLD_update_disease_names($_POST, $uniqueDiseases, $nameUpdatesFile, $oldFileLocation,  $newFileLocation){
   //$queueTable = $this->tables['vd_queue'];
   //for each updated disease name
   //$data = array('disease' => $updatedDisease);
   //$this->db->where('disease', $oldDiseaseName);
   //$this->db->update($queueTable, $data); 

   // Produces:
   // UPDATE $queueTable
   // SET disease = '{$updatedDisease}'
   // WHERE disease = $oldDiseaseName
    $submittedNameUpdates = fopen($nameUpdatesFile, "w");
    $queueTable = $this->tables['vd_queue'];
    foreach($uniqueDiseases as $disease){
      $string = str_replace(" ", "_", $disease);
      if($_POST[$string]){
        $newName = $_POST[$string];
        fwrite($submittedNameUpdates, $disease."\t".$newName);
        $sql = "SELECT DISTINCT disease FROM $queueTable";
        $query = $this->db->query($sql);
      }
      else{
        $newName=$disease;
        fwrite($submittedNameUpdates, $disease."\t".$disease);
        $sql = "SELECT DISTINCT disease FROM $queueTable";
        $query = $this->db->query($sql);
      }
  
    }
    $matchLocationUpdate = 0;
    $matchLocationOld = 7;
    $newDiseaseName = 1;
    $oldDiseaseName = 7;
    $updateFileLocation = $nameUpdatesFile;
    $replacementPairs = array(array($newDiseaseName, $oldDiseaseName));
    $returnVal = $this->update_variant($matchLocationUpdate, $matchLocationOld, $newFileLocation, $oldFileLocation, $updateFileLocation, $replacementPairs);
    return $returnVal;
  }
  /**
  * Expert Curation
  *
  * Takes three file locations. The old file, the file containing changes to be applied and
  * a location for the new file. The old and update file are read and a new file is produced
  * with the changes applied.
  *
  * @author Andrea Hallier
  * @input newFileLocation, oldFileLocation, updateFileLocation
  */

  public function load_expert_curations($expertCurations){
    $expertTable = $this->tables['expert_curations'];
    $expertLogTable = $this->tables['expert_log'];
    $submittedExpertCurations = fopen($expertCurations, "r");
    $sql="SELECT variation FROM $expertTable";
    $query = mysql_query($sql);
    $date = date ("Y-m-d H:i:s");
    $currentVariants = array();
    while($row = mysql_fetch_assoc($query))
    {
        $currentVariants[] = $row['variation'];
    }     
    $currentVariantsString = implode("','", $currentVariants);
    //return $currentVariants;
    $row = 1;
    while($line = fgets($submittedExpertCurations)){
      if($row != 1){
        $data = explode("\",\"", $line);
        $gene = (str_replace('"', "", $data[0]));
        $chr = ($data[1]);
        $pos = ($data[2]);
        $ref = ($data[3]);
        $alt = ($data[4]);
        $variation = ($data[5]);
        $path = ($data[6]);
        $disease = urlencode($data[7]);
        $pubmed = ($data[8]);
        $comments = urlencode($data[9]);
        $delete = ($data[10]);
        $disable = str_replace('"', "", $data[11]);
        //if exsists, copy old one to log
        $logit="insert into $expertLogTable (gene, chr, pos, ref, alt, variation, pathogenicity, disease, pubmed_id, comments, delete_on_release, disabled_curation, date_inserted) select * from $expertTable where variation = '$variation'";
        $logitR =  $this->db->query($logit);
        $upsert = "REPLACE INTO $expertTable (gene, chr, pos, ref, alt, variation, pathogenicity, disease, pubmed_id, comments, delete_on_release, disabled_curation, date_inserted) VALUES ('$gene','$chr','$pos','$ref','$alt','$variation','$path','$disease','$pubmed','$comments','$delete','$disable','$date')";
        $upsertR = $this->db->query($upsert);
        //$update = "UPDATE $expertTable SET pathogenicity='$path', disease='$disease', pubmed_id='$pubmed',comments='$comments',delete_on_release='$delete',disabled_curation='$disable',date_inserted='$date' WHERE chr=$chr AND pos=$pos AND ref='$ref' AND alt='$alt' AND gene='$gene'";
        //$insert = "INSERT INTO $expertTable (gene, chr, pos, ref, alt, variation, pathogenicity, disease, pubmed_id, comments, delete_on_release, disabled_curation, date_inserted) VALUES ('$gene','$chr','$pos','$ref','$alt','$variation','$path','$disease','$pubmed','$comments','$delete','$disable','$date')";
        //if ((sizeof($currentVariants))>0){
        //  $updateR = $this->db->query($update);
        //  //add updated variants to log!!!!!!!
        //}
        //if (sizeof($currentVariants)<=0 or $updateR->num_rows <= 0){  
        //  $insertR = $this->db->query($insert);
        //} 
      }
      $row ++;
    }

    $sql="SELECT variation FROM $expertTable";
    $query = mysql_query($sql) or die('cant get expert variations 2');
    $currentVariants = array();
    while($row = mysql_fetch_assoc($query))
    {
        $currentVariants[] = $row['variation'];
    }     
    $numUpdates = sizeof($currentVariants);
    return $numUpdates;
  }

  public function apply_expert_curations(){
    $expertTable = $this->tables['expert_curations'];
    $queueTable = $this->tables['vd_queue'];
    $reviewTable = $this->tables['reviews'];

    //update queue where curation is not disabled
    //$updateQueue = "UPDATE a SET a.pathogenicity = b.pathogenicity, a.disease = b.disease, a.pubmed_id = b.pubmed_id, a.comments = b.comments FROM $queueTable AS a INNER JOIN $expertTable AS b ON a.variation = b.variation WHERE b.delete_on_release != 'TRUE' AND b.disabled_curation != 'TRUE'";
    $updateQueue = "UPDATE $queueTable a INNER JOIN $expertTable b ON a.variation = b.variation SET a.pathogenicity = b.pathogenicity, a.disease = b.disease, a.pubmed_id = b.pubmed_id, a.comments = b.comments";
    $deleteVariants = "UPDATE $reviewTable SET scheduled_for_deletion = 1 WHERE variant_id IN (SELECT id FROM $queueTable WHERE variation IN (SELECT variation FROM $expertTable WHERE delete_on_release = 'TRUE' and disabled_curation != 'TRUE'))";
    //get all that are not in queue now
    //get id if in live table now
    //add to queue with largest live table id or matching live table id
    //add to reviews what was added to queue, be sure you are checking for deletes
    //$insertVariants  = "";
    $deleteR = $this->db->query($deleteVariants);
    $updateR = $this->db->query($updateQueue);
    $numUpdates = "Working on it";
    return $numUpdates; 
  }
  public function expert_curation($newFileLocation, $oldFileLocation, $updateFileLocation){
    $queueTable = $this->tables['vd_queue'];
    while($fileLine = fgets($updateFileLocation)){
      $lineArray=explode("\",\"", $fileLine);
      $newDisease=urlencode($lineArray[3]);
      $newPath=$lineArray[2];
      $variant=$lineArray[1];
      $newPubMedID=$lineArray[4];
      $sql = "UPDATE $queueTable SET disease='$newDisease', pathogenicity='$newPath', pubmedid='$newPubMedID' WHERE variant='$variant'";
      $query = $this->db->query($sql);
    }
    $query5 = $this->db->query("SELECT * from $queueTable INTO OUTFILE '/tmp/queueExpertUpdates$timeStamp.csv' FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\n';");
    exec("cp /tmp/queueExpertUpdates$timeStamp.csv /var/www/html/cordova_sites_ah/rdvd/tmp/queueExpertUpdates$timeStamp.csv");
  }
  public function OLD_expert_curation($newFileLocation, $oldFileLocation, $updateFileLocation){
    $updateDisease = 3;
    $updatePathogenicity = 2;
    $updateVariant = 1;
    $updatePubmedId = 4;
    $oldDisease = 7;
    $oldPathogenicity = 6;
    $oldVariant = 2;
    $oldPubmedId = 8;
    $replacementPairs = array( array($updateDisease, $oldDisease),
                               array($updatePathogenicity, $oldPathogenicity),
                               array($updatePubmedId, $oldPubmedId));

    $this->update_variant($updateVariant, $oldVariant, $newFileLocation, $oldFileLocation, $updateFileLocation, $replacementPairs);
    
    return $newFileLocation;
  }
  
  public function get_table_data($table, $path, $orderby){
    $queryTable = $this->tables["$table"];
    $data = fopen($path, "w");
    $sql="SELECT * 
    FROM $queryTable
    $orderby";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($data,implode("\r\n", $scoredata));
    }
    fclose($data);
    return $sqlResult;
  }

  public function get_var_log($timeStamp){

    $liveTable = $this->tables['vd_live'];
    $liveDataPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/varLog$timeStamp.csv";    
    $liveData = fopen($liveDataPath, "w");
    $sql="SELECT * 
    FROM $liveTable";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($liveData,implode("\r\n", $scoredata));
    }
    fclose($liveData);
    return $sqlResult;
  }
  public function get_expert_log($timeStamp){

    $liveTable = $this->tables['vd_live'];
    $liveDataPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/liveData$timeStamp.csv";    
    $liveData = fopen($liveDataPath, "w");
    $sql="SELECT * 
    FROM $liveTable";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($liveData,implode("\r\n", $scoredata));
    }
    fclose($liveData);
    return $sqlResult;
  }
  public function get_expert_data($timeStamp){

    $liveTable = $this->tables['vd_live'];
    $liveDataPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/liveData$timeStamp.csv";    
    $liveData = fopen($liveDataPath, "w");
    $sql="SELECT * 
    FROM $liveTable";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($liveData,implode("\r\n", $scoredata));
    }
    fclose($liveData);
    return $sqlResult;
  }
  public function get_live_data($timeStamp){

    $liveTable = $this->tables['vd_live'];
    $liveDataPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/liveData$timeStamp.csv";    
    $liveData = fopen($liveDataPath, "w");
    $sql="SELECT * 
    FROM $liveTable";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($liveData,implode("\r\n", $scoredata));
    }
    fclose($liveData);
    return $sqlResult;
  }
  public function get_queue_data($timeStamp){

    $queueTable = $this->tables['vd_queue'];
    $queueDataPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/queueData$timeStamp.csv";    
    $queueData = fopen($queueDataPath, "w");
    $sql="SELECT * 
    FROM $queueTable";
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($queueData,implode("\r\n", $scoredata));
    }
    fclose($queueData);
    return $sqlResult;
  }
  public function get_diff_stats($timeStamp){
    //create a file to wrtie to
    $liveTable = $this->tables['vd_live'];
    $queueTable = $this->tables['vd_queue'];
    $diffStatsPath = "/var/www/html/cordova_sites_ah/rdvd/tmp/diffStats$timeStamp.csv";    
    $diffStats = fopen($diffStatsPath, "w");
    //get stats
    $dropView = "DROP VIEW IF EXISTS commonVariants";
    $createView = "create view commonVariants AS select ot.variation, ot.gene, ot.pathogenicity as old_path, nt.pathogenicity as new_path, ot.disease as liveDisease, nt.disease as queueDisease, ot.comments as liveComments, nt.comments as queueComments from $liveTable ot inner join $queueTable nt where ot.variation=nt.variation";
    $sql="select g.gene as gene,num_old_var AS num_old_var, num_new_var AS num_new_var, added AS added, dropped AS dropped, num_path AS num_path, num_lp AS num_lp, num_us AS num_us, num_lb AS num_lb, num_b AS num_b, num_bs AS num_bs, old_num_path AS old_num_path, old_num_lp AS old_num_lp, old_num_us AS old_num_us, old_num_lb AS old_num_lb, old_num_b AS old_num_b, old_num_bs AS old_num_bs, changed AS changed, unchanged AS unchanged, num_p_to_lp AS num_p_to_lp, num_p_to_us AS num_p_to_us, num_p_to_lb AS num_p_to_lb, num_p_to_b AS num_p_to_b, num_p_to_bs AS num_p_to_bs, num_lp_to_p AS num_lp_to_p, num_lp_to_us AS num_lp_to_us, num_lp_to_lb AS num_lp_to_lb, num_lp_to_b AS num_lp_to_b, num_lp_to_bs AS num_lp_to_bs, num_us_to_p AS num_us_to_p, num_us_to_lp AS num_us_to_lp, num_us_to_lb AS num_us_to_lb, num_us_to_b AS num_us_to_b, num_us_to_bs AS num_us_to_bs, num_lb_to_p AS num_lb_to_p, num_lb_to_lp AS num_lb_to_lp, num_lb_to_us AS num_lb_to_us, num_lb_to_b AS num_lb_to_b, num_lb_to_bs AS num_lb_to_bs, num_b_to_p AS num_b_to_p, num_b_to_lp AS num_b_to_lp, num_b_to_us AS num_b_to_us, num_b_to_lb AS num_b_to_lb, num_b_to_bs AS num_b_to_bs, num_bs_to_p AS num_bs_to_p, num_bs_to_lp AS num_bs_to_lp, num_bs_to_us AS num_bs_to_us, num_bs_to_lb AS num_bs_to_lb, num_bs_to_b AS num_bs_to_b
    from
    (select distinct gene from (select distinct gene from $liveTable union select distinct gene from $queueTable) m) g
    left join
    (select gene, count(*) as num_old_var from $liveTable group by gene) ot
    on g.gene=ot.gene
    left join
    (select gene, count(*) as num_new_var from $queueTable group by gene) nt
    on g.gene=nt.gene
    left join
    (select gene, count(*) as added from $queueTable where variation not in (select variation from commonVariants) group by gene) a
    on g.gene=a.gene
    left join
    (select gene, count(*) as dropped from $liveTable where variation not in (select variation from commonVariants) group by gene) d
    on g.gene=d.gene
    left join
    (select gene, count(*) as num_path from $queueTable where pathogenicity='Pathogenic' group by gene) pn
    on g.gene=pn.gene
    left join
    (select gene, count(*) as num_lp from $queueTable where pathogenicity='Likely pathogenic' group by gene) lpn
    on g.gene=lpn.gene
    left join
    (select gene, count(*) as num_us from $queueTable where pathogenicity='Unknown significance' group by gene) usn
    on g.gene=usn.gene
    left join
    (select gene, count(*) as num_lb from $queueTable where pathogenicity='Likely benign' group by gene) lbn
    on g.gene=lbn.gene
    left join
    (select gene, count(*) as num_b from $queueTable where pathogenicity='Benign' group by gene) bn
    on g.gene=bn.gene
    left join
    (select gene, count(*) as num_bs from $queueTable where pathogenicity='Benign*' group by gene) bsn
    on g.gene=bsn.gene
    left join
    (select gene, count(*) as old_num_path from $liveTable where pathogenicity='Pathogenic' group by gene) pno
    on g.gene=pno.gene
    left join
    (select gene, count(*) as old_num_lp from $liveTable where pathogenicity='Likely pathogenic' group by gene) lpno
    on g.gene=lpno.gene
    left join
    (select gene, count(*) as old_num_us from $liveTable where pathogenicity='Unknown significance' group by gene) usno
    on g.gene=usno.gene
    left join
    (select gene, count(*) as old_num_lb from $liveTable where pathogenicity='Likely benign' group by gene) lbno
    on g.gene=lbno.gene
    left join
    (select gene, count(*) as old_num_b from $liveTable where pathogenicity='Benign' group by gene) bno
    on g.gene=bno.gene
    left join
    (select gene, count(*) as old_num_bs from $liveTable where pathogenicity='Benign*' group by gene) bsno
    on g.gene=bsno.gene
    left join
    (select gene, count(*) as changed from commonVariants where old_path != new_path group by gene) c
    on g.gene=c.gene
    left join
    (select gene, count(*) as unchanged from commonVariants where old_path = new_path group by gene) u
    on g.gene=u.gene
    left join
    (select gene, count(*) as num_p_to_lp from commonVariants where old_path='Pathogenic' and new_path='Likely pathogenic' group by gene) t1
    on g.gene=t1.gene
    left join
    (select gene, count(*) as num_p_to_us from commonVariants where old_path='Pathogenic' and new_path='Unknown significance' group by gene) t2
    on g.gene = t2.gene
    left join
    (select gene, count(*) as num_p_to_lb from commonVariants where old_path='Pathogenic' and new_path='Likely benign' group by gene) t3
    on g.gene = t3.gene
    left join
    (select gene, count(*) as num_p_to_b from commonVariants where old_path='Pathogenic' and new_path='Benign' group by gene) t4
    on g.gene = t4.gene
    left join
    (select gene, count(*) as num_p_to_bs from commonVariants where old_path='Pathogenic' and new_path='Benign*' group by gene) t5
    on g.gene = t5.gene
    left join
    (select gene, count(*) as num_lp_to_p from commonVariants where old_path='Likely pathogenic' and new_path='Pathogenic' group by gene) t6
    on g.gene = t6.gene
    left join
    (select gene, count(*) as num_lp_to_us from commonVariants where old_path='Likely pathogenic' and new_path='Unknown significance' group by gene) t7
    on g.gene = t7.gene
    left join
    (select gene, count(*) as num_lp_to_lb from commonVariants where old_path='Likely pathogenic' and new_path='Likely benign' group by gene) t8
    on g.gene = t8.gene
    left join
    (select gene, count(*) as num_lp_to_b from commonVariants where old_path='Likely pathogenic' and new_path='Benign' group by gene) t9
    on g.gene = t9.gene
    left join
    (select gene, count(*) as num_lp_to_bs from commonVariants where old_path='Likely pathogenic' and new_path='Benign*' group by gene) t10
    on g.gene = t10.gene
    left join
    (select gene, count(*) as num_us_to_p from commonVariants where old_path='Unknown significance' and new_path='Pathogenic' group by gene) t11
    on g.gene = t11.gene
    left join
    (select gene, count(*) as num_us_to_lp from commonVariants where old_path='Unknown significance' and new_path='Likely pathogenic' group by gene) t12
    on g.gene = t12.gene
    left join
    (select gene, count(*) as num_us_to_lb from commonVariants where old_path='Unknown significance' and new_path='Likely benign' group by gene) t13
    on g.gene = t13.gene
    left join
    (select gene, count(*) as num_us_to_b from commonVariants where old_path='Unknown significance' and new_path='Benign' group by gene) t14
    on g.gene = t14.gene
    left join
    (select gene, count(*) as num_us_to_bs from commonVariants where old_path='Unknown significance' and new_path='Benign*' group by gene) t15
    on g.gene = t15.gene
    left join
    (select gene, count(*) as num_lb_to_p from commonVariants where old_path='Likely benign' and new_path='Pathogenic' group by gene) t16
    on g.gene = t16.gene
    left join
    (select gene, count(*) as num_lb_to_lp from commonVariants where old_path='Likely benign' and new_path='Likely pathogenic' group by gene) t17
    on g.gene = t17.gene
    left join
    (select gene, count(*) as num_lb_to_us from commonVariants where old_path='Likely benign' and new_path='Unknown significance' group by gene) t18
    on g.gene = t18.gene
    left join
    (select gene, count(*) as num_lb_to_b from commonVariants where old_path='Likely benign' and new_path='Benign' group by gene) t19
    on g.gene = t19.gene
    left join
    (select gene, count(*) as num_lb_to_bs from commonVariants where old_path='Likely benign' and new_path='Benign*' group by gene) t20
    on g.gene = t20.gene
    left join
    (select gene, count(*) as num_b_to_p from commonVariants where old_path='Benign' and new_path='Pathogenic' group by gene) t21
    on g.gene = t21.gene
    left join
    (select gene, count(*) as num_b_to_lp from commonVariants where old_path='Benign' and new_path='Likely pathogenic' group by gene) t22
    on g.gene = t22.gene
    left join
    (select gene, count(*) as num_b_to_us from commonVariants where old_path='Benign' and new_path='Unknown significance' group by gene) t23
    on g.gene = t23.gene
    left join
    (select gene, count(*) as num_b_to_lb from commonVariants where old_path='Benign' and new_path='Likely benign' group by gene) t24
    on g.gene = t24.gene
    left join
    (select gene, count(*) as num_b_to_bs from commonVariants where old_path='Benign' and new_path='Benign*' group by gene) t25
    on g.gene = t25.gene
    left join
    (select gene, count(*) as num_bs_to_p from commonVariants where old_path='Benign*' and new_path='Pathogenic' group by gene) t26
    on g.gene = t26.gene
    left join
    (select gene, count(*) as num_bs_to_lp from commonVariants where old_path='Benign*' and new_path='Likely pathogenic' group by gene) t27
    on g.gene = t27.gene
    left join
    (select gene, count(*) as num_bs_to_us from commonVariants where old_path='Benign*' and new_path='Unknown significance' group by gene) t28
    on g.gene = t28.gene
    left join
    (select gene, count(*) as num_bs_to_lb from commonVariants where old_path='Benign*' and new_path='Likely benign' group by gene) t29
    on g.gene = t29.gene
    left join
    (select gene, count(*) as num_bs_to_b from commonVariants where old_path='Benign*' and new_path='Benign' group by gene) t30
    on g.gene = t30.gene";
    //INTO OUTFILE '$diffStatsPath'
    //FIELDS TERMINATED BY ','
    //ENCLOSED BY '\"'
    //LINES TERMINATED BY '\n'"; 
    
    $dropR = mysql_query($dropView);
    $viewR = mysql_query($createView);  
    $sqlResult = mysql_query($sql);
    //write to file
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $scoredata[] = implode("; ", $row);
      fwrite($diffStats,implode("\r\n", $scoredata));
    }

    #--Pathogenic Demoted
    $sql1 = "select * from commonVariants where old_path='Pathogenic' and new_path='Likely pathogenic'";
    $sql2 = "select * from commonVariants where old_path='Pathogenic' and new_path='Unknown significance'";
    $sql3 = "select * from commonVariants where old_path='Pathogenic' and new_path='Likely benign'";
    $sql4 = "select * from commonVariants where old_path='Pathogenic' and new_path='Benign'";
    $sql5 = "select * from commonVariants where old_path='Pathogenic' and new_path='Benign*'";
    $sql6 = "select * from $liveTable where pathogenicity='Pathogenic' and variation not in (select variation from $queueTable)";

    #--LP Demoted
    $sql7 = "select * from commonVariants where old_path='Likely pathogenic' and new_path='Unknown significance'";
    $sql8 = "select * from commonVariants where old_path='Likely pathogenic' and new_path='Likely benign'";
    $sql9 = "select * from commonVariants where old_path='Likely pathogenic' and new_path='Benign'";
    $sql10 = "select * from commonVariants where old_path='Likely pathogenic' and new_path='Benign*'";
    $sql11 = "select * from $liveTable where pathogenicity='Likely pathogenic' and variation not in (select variation from $queueTable)";

    #--Pathogenic Promoted
    $sql12 = "select * from commonVariants where old_path='Likely pathogenic' and new_path='Pathogenic'";
    $sql13 = "select * from commonVariants where old_path='Unknown significance' and new_path='Pathogenic'";
    $sql14 = "select * from commonVariants where old_path='Likely benign' and new_path='Pathogenic'";
    $sql15 = "select * from commonVariants where old_path='Benign' and new_path='Pathogenic'";
    $sql16 = "select * from commonVariants where old_path='Benign' and new_path='Benign*'";

    #--LP Promoted
    $sql17 = "select * from commonVariants where old_path='Unknown significance' and new_path='Likely pathogenic'";
    $sql18 = "select * from commonVariants where old_path='Likely benign' and new_path='Likely pathogenic'";
    $sql19 = "select * from commonVariants where old_path='Benign' and new_path='Likely pathogenic'";
    $sql20 = "select * from commonVariants where old_path='Benign*' and new_path='Likely pathogenic'";
    //query and write results
    $sqlResult = mysql_query($sql1);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql2);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql3);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql4);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql5);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql6);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql7);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql8);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql9);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql10);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql11);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql12);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql13);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql14);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql15);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql16);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql17);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql18);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql19);
    $this->write_sql_result_to_file($sqlResult, $diffStats);
    $sqlResult = mysql_query($sql20);
    $this->write_sql_result_to_file($sqlResult, $diffStats);

    
    
    fclose($diffStats);

    return $sqlResult;
  }

  public function write_sql_results_to_file($sqlResult, $fileHandle){
    while($row = mysql_fetch_assoc($sqlResult))
    {
      $data[] = implode("; ", $row);
      fwrite($fileHandle,implode("\r\n", $data));
    }
  }
  /**
  * Update Variant
  *
  * This function is very simmilar to a database update. It finds the corresponding entry
  * the user wants to update and updates the list of entrys to that reccord the user specifies.
  *
  * matchLocationUpdateFile:The index of the vcf to look for an entry match,
  *                         in most cases this is variant name or id.
  * matchLocationOldFile:Same as above but the corresponding index in the old file.
  * newFileLocation: Location to write updated file information to.
  * oldFileLocation: Location to find old file at.
  * updateFileLocation: Location to find data used to update the old file.
  * replacementPairs: An array of pairs of numbers corresponding to the index of
  *                     an element of interest in the old entry and the update entry.
  *     For example: If we wanted to update disease name. The index
  *                   of the disease name in the old file line is 7
  *                   and the index where disease name can be found in
  *                   the update file is 3, the pair would be (3,7). Many
  *                   of these relationships may be passed.
  *
  * 
  * @author Andrea Hallier
  * @input $matchLocationUpdateFile, $matchLocationOldFile, $newFileLocation, $oldFileLocation, $updateFileLocation, $replacementPairs
  */
  public function update_variant($matchLocationUpdateFile, $matchLocationOldFile, $newFileLocation, $oldFileLocation, $updateFileLocation, $replacementPairs){
    $oldFile = fopen($oldFileLocation, "r");
    $updateFile = fopen($updateFileLocation, "r");
    $newFile = fopen($newFileLocation, "w");
    $updateFileLines = file($updateFileLocation);
    $returnArray = array();
    $lineUpdated = false;
    array_push($returnArray, array($oldFileLocation, $newFileLocation, $updateFileLocation));
    while($oldFileLine = fgets($oldFile)){
      //array_push($returnArray, $oldFileLine);  
      $oldLineExploded = explode("\t", $oldFileLine);
      foreach($updateFileLines as $updateFileLine){
        $updateLineExploded = explode("\t", $updateFileLine);
        //return strval($updateLineExploded[$matchLocationUpdateFile]);
        if(sizeof($oldLineExploded) > $matchLocationOldFile and sizeof($updateLineExploded) > $matchLocationUpdateFile){
          //array_push($returnArray,array($oldLineExploded[$matchLocationOldFile],$updateLineExploded[$matchLocationUpdateFile],(strtolower($oldLineExploded[$matchLocationOldFile]) == strtolower($updateLineExploded[$matchLocationUpdateFile]))));
          if(strtolower($oldLineExploded[$matchLocationOldFile]) == strtolower($updateLineExploded[$matchLocationUpdateFile])) {
            //return ($oldLineExploded[$matchLocationOldFile] == $updateLineExploded[$matchLocationUpdateFile]);
            array_push($returnArray,array($oldLineExploded[$matchLocationOldFile],$updateLineExploded[$matchLocationUpdateFile],strcasecmp($oldLineExploded[$matchLocationOldFile],$updateLineExploded[$matchLocationUpdateFile])));
                          
            foreach($replacementPairs as $pair){
              $updateLocation = $pair[0];
              $oldLocation = $pair[1];
              $oldLineExploded[$oldLocation]=$updateLineExploded[$updateLocation];
            }
            $updatedLine = implode("\t", $oldLineExploded);
            $lineUpdated = true;
          }
        }  
      }
      if($lineUpdated){
        fwrite($newFile, $updatedLine);
      }
      else{
        fwrite($newFile, $oldFileLine);
      }
      $lineUpdated = false;

    }
    return $returnArray;
  }
  
  public function equals_ignore_case($s, $t) {
    return strtolower($s) == strtolower($t); 
  }

  public function UploadCADIData($finalFileLocation){
      $finalFile = fopen($finalFileLocation, "r");
      $fileLines = file($finalFileLocation);
      $pattern="/\\N/";
      foreach($fileLines as $line){
        $lineArray = explode("\t", $line);
        
        foreach($lineArray as $entry){
          preg_replace ($pattern," ",$entry);
          str_replace("\\N", " ", $entry);
          if(strcmp('\\N', $entry) == 0){
            $entry = " ";
          }
        }
        //if there is a variant in this line
        if(isset($lineArray[1])){
          $variation = $lineArray[1];
          //return($lineArray[1]);
          //if there are missing values, add space holder to array
          if(count($lineArray)<71){
            for($i = count($lineArray); $i <= 70; $i++){
              array_push($lineArray, " ");
            }
          }
          //if(strcmp($lineArray[0], "id") !== 0){
            //assign everything to data array to be put into database
            
            
            
                      //'summary_insilico' => $lineArray[10],
            
                      //'summary_frequency' => $lineArray[11],
                      //'summary_published' => $lineArray[12],
                      //'lrt_omega'=> $lineArray[14],
                      //'gerp_nr' => $lineArray[17],
                      //'evs_all_an' => $lineArray[24],
                      //'evs_ea_an' => $lineArray[27],
                      //'evs_aa_an' => $lineArray[30],
                      //'tg_all_an'=> $lineArray[33],
                      //'tg_afr_an'=> $lineArray[36],
                      //'tg_amr_an'=> $lineArray[39],
        $data = array('variation' => $lineArray[1],
                      'gene' => $lineArray[2],
                      'hgvs_nucleotide_change' => $lineArray[3],
                      'hgvs_protein_change' => $lineArray[4],
                      'variantlocale' => $lineArray[5],
                      'pathogenicity' => $lineArray[6],
                      'disease' => urldecode($lineArray[7]),
                      'pubmed_id' => $lineArray[8],
                      'dbsnp' => $lineArray[9],
                      'summary_insilico' => ".",
                      'summary_frequency' => ".",
                      'summary_published' => ".",
                      'comments' => $lineArray[10],
                      'lrt_omega'=> ".",
                      'sift_score' => $lineArray[11],
                      'sift_pred'=> $lineArray[12],
                      'polyphen2_score' => $lineArray[13],
                      'polyphen2_pred'=> $lineArray[14],
                      'mutationtaster_score' => $lineArray[15],
                      'mutationtaster_pred' => $lineArray[16],
                      'gerp_nr' => ".",
                      'gerp_rs'=> $lineArray[17],
                      'gerp_pred' => $lineArray[18],
                      'phylop_score' => $lineArray[19],
                      'phylop_pred' => $lineArray[20],
                      'lrt_score' => $lineArray[21],
                      'lrt_pred' => $lineArray[22],
                      'evs_ea_ac' => $lineArray[26],
                      'evs_ea_af' => $lineArray[28],
                      'evs_aa_ac' => $lineArray[29],
                      'evs_aa_af' => $lineArray[31],
                      'evs_all_ac' => $lineArray[23],
                      'evs_all_af' => $lineArray[25],
                      'otoscope_aj_ac' => $lineArray[53],
                      'otoscope_aj_af' => $lineArray[55],
                      'otoscope_co_ac' => $lineArray[56],
                      'otoscope_co_af' => $lineArray[58],
                      'otoscope_us_ac' => $lineArray[59],
                      'otoscope_us_af' => $lineArray[61],
                      'otoscope_jp_ac' => $lineArray[62],
                      'otoscope_jp_af' => $lineArray[64],
                      'otoscope_es_ac' => $lineArray[65],
                      'otoscope_es_af' => $lineArray[67],
                      'otoscope_tr_ac' => $lineArray[68],
                      'otoscope_tr_af' => $lineArray[70],
                      'otoscope_all_ac' => $lineArray[50],
                      'otoscope_all_af' => $lineArray[52],
                      'tg_afr_ac'=> $lineArray[35],
                      'tg_afr_af'=> $lineArray[37],
                      'tg_eur_ac'=> $lineArray[44],
                      'tg_eur_af'=> $lineArray[46],
                      'tg_amr_ac'=> $lineArray[38],
                      'tg_amr_af'=> $lineArray[40],
                      'tg_asn_ac'=> ".",
                      'tg_asn_af'=> ".",
                      'tg_all_ac'=> $lineArray[32],
                      'tg_all_af' => $lineArray[34] );
   /* 
            $data = array('variation' => $lineArray[1],
                      'gene' => $lineArray[2],
                      'hgvs_nucleotide_change' => $lineArray[3],
                      'hgvs_protein_change' => $lineArray[4],
                      'variantlocale' => $lineArray[5],
                      'pathogenicity' => $lineArray[6],
                      'disease' => $lineArray[7],
                      'pubmed_id' => $lineArray[8],
                      'dbsnp' => $lineArray[9],
                      'comments' => $lineArray[10],
                      'sift_score' => $lineArray[11],
                      'sift_pred'=> $lineArray[12],
                      'polyphen2_score' => $lineArray[13],
                      'polyphen2_pred'=> $lineArray[14],
                      'mutationtaster_score' => $lineArray[15],
                      'mutationtaster_pred' => $lineArray[16],
                      'gerp_rs'=> $lineArray[17],
                      'gerp_pred' => $lineArray[18],
                      'phylop_score' => $lineArray[19],
                      'phylop_pred' => $lineArray[20],
                      'lrt_score' => $lineArray[21],
                      'lrt_pred' => $lineArray[22],
                      'evs_all_ac' => $lineArray[23],
                      'evs_all_af' => $lineArray[25],
                      'evs_ea_ac' => $lineArray[26],
                      'evs_ea_af' => $lineArray[28],
                      'evs_aa_ac' => $lineArray[29],
                      'evs_aa_af' => $lineArray[31],
                      'tg_all_ac'=> $lineArray[32],
                      'tg_all_af'=> $lineArray[34],
                      'tg_afr_ac'=> $lineArray[35],
                      'tg_afr_af'=> $lineArray[37],
                      'tg_amr_ac'=> $lineArray[38],
                      'tg_amr_af'=> $lineArray[40],
                      'tg_eas_ac'=> $lineArray[41],
                      'tg_eas_af'=> $lineArray[43],
                      'tg_eur_ac'=> $lineArray[44],
                      'tg_eur_af'=> $lineArray[46],
                      'otoscope_all_ac' => $lineArray[50],
                      'otoscope_all_af' => $lineArray[52],
                      'otoscope_aj_ac' => $lineArray[53],
                      'otoscope_aj_af' => $lineArray[55],
                      'otoscope_co_ac' => $lineArray[56],
                      'otoscope_co_af' => $lineArray[58],
                      'otoscope_us_ac' => $lineArray[59],
                      'otoscope_us_af' => $lineArray[61],
                      'otoscope_jp_ac' => $lineArray[62],
                      'otoscope_jp_af' => $lineArray[64],
                      'otoscope_es_ac' => $lineArray[65],
                      'otoscope_es_af' => $lineArray[67],
                      'otoscope_tr_ac' => $lineArray[68],
                      'otoscope_tr_af' => $lineArray[70] );
     */     
                      //'tg_sas_ac'=> $lineArray[47],
                      //'tg_sas_af'=> $lineArray[49],
                      //'otoscope_tr_an' => $lineArray[69],
                      //'tg_eas_an'=> $lineArray[42],
                      //'tg_eur_an'=> $lineArray[45],
                      //'tg_sas_an'=> $lineArray[48],
                      //'otoscope_all_an' => $lineArray[51],
                      //'otoscope_aj_an' => $lineArray[54],
                      //'otoscope_co_an' => $lineArray[57],
                     //'otoscope_us_an' => $lineArray[60],
                      //'otoscope_jp_an' => $lineArray[63],
                      //'otoscope_es_an' => $lineArray[66],
            $id = $this->create_new_variant($variation, FALSE, TRUE, $data);
        //}
            //return($id);
      }
    }
  }
  public function remove_variantCADI_files($time_stamp){
    exec("rm /asap/cordova_pipeline/*$time_stamp*.txt");
    exec("rm /asap/variant-CADI/tmp/*$time_stamp*.txt");
  }








}

/* End of file variations_model.php */
/* Location: ./application/models/variations_model.php */

