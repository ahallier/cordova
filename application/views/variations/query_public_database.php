<ul class="nav nav-tabs">
  <li><a href="">Step 1</a></li>
  <li class="active"><a href="">Step 2</a></li>
  <li><a href="">Step 3</a></li>
</ul>
<?php
  $attributes = array('class' => 'query_public_database',
                      'id' => 'query_public_database_form');
?>
<h1>Query Public Databases</h1>
<h2>Your file was successfully uploaded!</h3>
<p>You have chosen to submit: </p>
<?php echo $genes; ?>
<br/>
<?php echo form_open('variations/query_public_database', $attributes)?>
<input type="submit" value="submit" id="submit" name="submit"></input>
</form>
