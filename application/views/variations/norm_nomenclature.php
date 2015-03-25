<?php
$attributes = array('id'    => 'form_upload_genes',
                     'class' => 'rounded',
                    );
?>
<ul class="nav nav-tabs">
  <li><a href="">Step1</a></li>
  <li><a href="">Step2</a></li>
  <li class="active"><a href="">Step3</a></li>
</ul>
<h1>Select Prefered Nomenclature</h1>
<p>Below to the left is a list of gathered phenotypes from the public databases that were queried. To the right please enter your team's prefered title to normalize nomenclature throught your database.</p>
<div>
  <h3>Public Database Nomenclature</h3>
<?php echo form_open('variations/norm_nomenclature', $attributes);?>
  <table style="width:90%" class="table-striped">
  <?php
  foreach ($uniqueDiseases as $disease){
  echo"<tr><td>".$disease."</td><td><input type='text' name='".$disease."'></input></td></tr>";
  }?>
</div>
<div>
  <button class="btn btn-success pull-right">Submit</button>
</div>
</form>
