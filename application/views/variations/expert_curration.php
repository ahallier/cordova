<ul class="nav nav-tabs">
   <li class="active"><a href="">Step 1</a></li>
   <li><a href="">Step 2</a></li>
   <li><a href="">Step 3</a></li>
</ul>
<h1> Step 4: Expert Curration</h1>
<br/>
<?php
  $attributes = array('id'    => 'form_expert_curration',
                       'class' => 'rounded',
                      );
   //echo $error;
  echo form_open_multipart('variations/expert_curration', $attributes);
?>
  <div class="span4">
    <p>If you wish to over ride any information gathered through this pipeline please uplaod a file with the information you wish to change. Your upload file format should be a text file containing gene_name, variation, disease_name, and pathogenicity. This file must be tab delimited and each variation separated by a new line character. The variation must match the exsisting file variation name exactly. The current file can be downloaded <a href="">here</a>for reference.</p> 
    <input type="file" id="file" name="file"/>
    <br/>
    <br/>
    <input type="submit" value="Upload" name="file-expert" class="btn btn-success"/>
    <br/>
  </div>
  </form>

