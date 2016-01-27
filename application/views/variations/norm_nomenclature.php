    <style>

        <!-- Progress with steps -->

            ol.progtrckr {
                    margin: 0;
                            padding: 0;
                                    list-style-type: none;
                                        }

                       ol.progtrckr li {
                                                    display: inline-block;
                                                            text-align: center;
                                                                    line-height: 3em;
                                                                        }

                                                                            ol.progtrckr[data-progtrckr-steps="2"] li { width: 49%; }
                                                                                ol.progtrckr[data-progtrckr-steps="3"] li { width: 33%; }
                                                                                    ol.progtrckr[data-progtrckr-steps="4"] li { width: 24%; }
                                                                                        ol.progtrckr[data-progtrckr-steps="5"] li { width: 19%; }
                                                                                            ol.progtrckr[data-progtrckr-steps="6"] li { width: 16%; }
                                                                                                ol.progtrckr[data-progtrckr-steps="7"] li { width: 14%; }
                                                                                                    ol.progtrckr[data-progtrckr-steps="8"] li { width: 12%; }
                                                                                                        ol.progtrckr[data-progtrckr-steps="9"] li { width: 11%; }

                                                                                                            ol.progtrckr li.progtrckr-done {
                                                                                                                    color: black;
                                                                                                                            border-bottom: 4px solid yellowgreen;
                                                                                                                                }
                                                                                                                                    ol.progtrckr li.progtrckr-todo {
                                                                                                                                            color: silver; 
                                                                                                                                                    border-bottom: 4px solid silver;
                                                                                                                                                        }

                                                                                                                                                            ol.progtrckr li:after {
                                                                                                                                                                    content: "\00a0\00a0";
                                                                                                                                                                        }
                                                                                                                                                                            ol.progtrckr li:before {
                                                                                                                                                                                    position: relative;
                                                                                                                                                                                            bottom: -2.5em;
                                                                                                                                                                                                    float: left;
                                                                                                                                                                                                            left: 50%;
                                                                                                                                                                                                                    line-height: 1em;
                                                                                                                                                                                                                        }
                                                                                                                                                                                                                            ol.progtrckr li.progtrckr-done:before {
                                                                                                                                                                                                                                    content: "\2713";
                                                                                                                                                                                                                                            color: white;
                                                                                                                                                                                                                                                    background-color: yellowgreen;
                                                                                                                                                                                                                                                            height: 1.2em;
                                                                                                                                                                                                                                                                    width: 1.2em;
                                                                                                                                                                                                                                                                            line-height: 1.2em;
                                                                                                                                                                                                                                                                                    border: none;
                                                                                                                                                                                                                                                                                            border-radius: 1.2em;
                                                                                                                                                                                                                                                                                                }
                                                                                                                                                                                                                                                                                                    ol.progtrckr li.progtrckr-todo:before {
                                                                                                                                                                                                                                                                                                            content: "\039F";
                                                                                                                                                                                                                                                                                                                    color: silver;
                                                                                                                                                                                                                                                                                                                            background-color: white;
                                                                                                                                                                                                                                                                                                                                    font-size: 1.5em;
                                                                                                                                                                                                                                                                                                                                            bottom: -1.6em;
                                                                                                                                                                                                                                                                                                                                                }

                                                                                                                                                                                                                                                                                                                                                </style>

<ol class="progtrckr" data-progtrckr-steps="5">
     <li class="progtrckr-done">Upload Genes</li>
         <li class="progtrckr-done">Gather Variants</li>
             <li class="progtrckr-done">Normalize</li>
                 <li class="progtrckr-todo">Expert Curation</li>
                     <li class="progtrckr-todo">Release Changes</li>
                     </ol>
       

<?php
$attributes = array('id'    => 'form_upload_genes',
                     'class' => 'rounded',
                    );
?>
<h1>Select Preferred Nomenclature</h1>
<p>Below is a list of gathered phenotypes from the public databases that were queried. Please enter your team's preferred nomenclature for each phenotype to normalize the nomenclature throughout your database. To review any errors that may have occured durring collection look <a href="/asap/cordova_pipeline/myvariants.error_log" download="variant-CADIerrors.txt">here</a>.</p>
<div>
  <h3>Public Database Nomenclature</h3>
<?php echo form_open("variations/norm_nomenclature/$time_stamp", $attributes);?>
  
  <?php
   foreach ($uniqueDiseases as $disease){
     echo
     "<div class='control-group'>
       <label class='control-label' for='".$disease."'>".urldecode($disease)."</label>
        <div class='controls'>
          <input class='align-right' type='text' name='".$disease."' id='".$disease."'></input>
        </div>
      </div>";   
   }
  ?>
</div>
<div>
  <input type="submit" name="submit" id="submit" value="submit"></input>
</div>
</form>
