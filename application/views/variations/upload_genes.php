<ul class="nav nav-tabs">
  <li class="active"><a href="">Step 1</a></li>
  <li><a href="">Step 2</a></li>
  <li><a href="">Step 3</a></li>
</ul>
<h1> Step 1: Upload Genes</h1>
<br/>
<?php
$attributes = array('id'    => 'form_upload_genes',
                    'class' => 'rounded',
                   );
//echo $error;
echo form_open_multipart('variations/upload_genes', $attributes);
?>
    <div class="span4">
      <p>To begin the process of initializing your varaiation database please upload a gene file</p>
    
      <input type="file" id="file" name="file"/>
      <br/>
      <br/>
      <input type="submit" value="Upload" name="file-submit" class="btn btn-success"/>
      <br/>
    </div>
  </form>
    <div class="span2">
      Or..
    </div>
  <?php echo form_open('variations/upload_genes', $attributes);?>
    <div class="span4">
      <p> Enter genes of interest in the text box. Each gene entered in the text box should be separated by a new line.</b><p/>
      <textarea rows="4" cols="100" id="text" name="text"></textarea>
    </div>
    <br/><br/>
    <input type="submit" value="Upload" name="text-submit" class="btn btn-success"/>
    </form>
<!--
progress bar maybe?
--!>
