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
                  <li class="progtrckr-done">Expert Curation</li>
                      <li class="progtrckr-todo">Release Changes</li>
                      </ol>

<h1>Expert Curation</h1>
<br/>
<?php
  $attributes = array('id'    => 'form_expert_curration',
                       'class' => 'rounded',
                      );
   //echo $error;
  echo form_open_multipart("variations/expert_curration/$time_stamp", $attributes);
?>
  <div class="span6">
    <p>If you wish to override any information gathered through this pipeline please upload a .txt file with the information you wish to change. The variation must match the existing file variation name exactly. </p>
    <p>The current list of variations and their data points can be downloaded <a href="/asap/variant-CADI/tmp/diseaseNameUpdates<?php echo $time_stamp?>.txt." download="variant-CADIvariants">here</a> for reference.</p> 
    <input type="file" id="file" name="file"/>
    <br/>
    <br/>
    <input type="submit" value="Upload" name="file-expert" class="btn btn-success"/>
    <br/>
  </div>
  </form>
  <div class = "span2">
  </div>
  <div class = "span4">
    <p>This is an example of formatting required for the input file.</p>
    <img src="<?php echo site_url('assets/editor/img/expertDataExample2.jpg'); ?>">
    <p>The variation name must match exactly to that in the file. Please surround each point with quotations and separate with a comma. Please insert a new line between each row.</p>
  </div>
