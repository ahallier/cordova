<h1>Expert Curation</h1>
<br/>
<?php
  $attributes = array('id'    => 'form_expert_curration',
                       'class' => 'rounded',
                      );
   //echo $error;
  echo form_open_multipart("variations/expert_curration/$time_stamp", $attributes);
?>
  <div>
    <p>If you wish to override any information gathered through this pipeline please upload a .csv file with the information you wish to change. The variation must match the existing file variation name exactly. These curations will be maintainted in the Cordova database for easy application to variations in the queue.</p>
  </div>
  <div class = "span6">
    <p>Upload a csv file describing your expert curation data. Any entries with matching variant to an exsisting variant in the Cordova expert_curations data table  will be updated instead of inserted into the database.
    <br/>
    <br/>Download <a type="application/octet-stream" href="http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/expertDataTemplate.csv" download="expertDataTemplate.csv">Template</a></p>
    <input type="file" id="file" name="file"/>
    <br/>
    <input type="submit" value="Upload" name="file-expert" class="btn btn-success"/>
    <br/>
    <br/>
    After expert curations have been submitted, select Apply Curations to apply these curations to the data in the queue prior to release.
    <br/>
    <input type="submit" value="Apply Curations" name="apply-curations" class="btn btn-success"/>
  </div>
  </form>
  <div class = "span1">
  </div>
  <div class = "span3">
    <p>Download <a type="application/octet-stream" href="http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/tmp/queue<?php echo $time_stamp?>.csv" download="variant-CADIvariants.csv">Queue Data</a> 
    <br/>Download <a type="application/octet-stream" href="http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/tmp/expertData<?php echo $time_stamp?>.csv" download="expertData.csv">Expert Curations Data</a> 
    <br/>Download <a type="application/octet-stream" href="http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/tmp/expertDataLog<?php echo $time_stamp?>.csv" download="expertDataLog.csv">Expert Curations Log</a> 
    <br/>Download <a type="application/octet-stream" href="http://cordova-dev.eng.uiowa.edu/cordova_sites_ah/rdvd/expertDataTemplate.csv" download="expertDataTemplate.csv">Template</a></p>
  </div>
  <!--
    <p>This is an example of formatting required for the input file.</p>
    <img src="<?php echo site_url('assets/editor/img/expertDataExample2.jpg'); ?>">
    <p>The variation name must match exactly to that in the file. Please surround each point with quotations and separate with a comma. Please insert a new line between each row.</p>
  </div>-->
