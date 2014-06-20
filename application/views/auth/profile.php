
<h1>Profile<h1> 
<?php
$attr = array('autocomplete' => 'off');
echo form_open(uri_string(), $attr);//what is this path?

//echo "<label for='firstname'>First name:</label>". form_input('username', set_value($user->username));
//echo "Password : ". form_input('password', set_value($user->password));
//echo "Username : ". form_input('username', set_value($user->username));
//echo form_submit('submit', 'Submit');
//echo form_close();
?>
        <label for="first_name">First name:</label> 
        <input type="text" name="first_name" value=<?php echo $user->first_name?>>
        <label for='last_name'>Last name:</label>  
        <input type='text' name='last_name' value=<?php echo $user->last_name?>>
        <label for='username'>Username: </label> 
        <input type='text' name='username' value=<?php echo $user->username?>>
        <label for='email'>Email: </label> 
  
        <input type='text' name='email' value=<?php echo $user->email?>>
        <label for='company'>Company Name: </label> 
        <input type='text' name='company' value=<?php echo $user->company?>>
        <label for="phone">Phone: </label> 
        <input type="text" name="phone" value=<?php echo $user->phone?>>
        <label for="password">New Password: </label> 
        <input type="text" name="password">
        <label for="passwordconfirm">Confirm New Password: </label> 
        <input type="text" name="passwordconfirm"><br>
        <input type="submit" value="Update">
    </form> 
<!--
      <p>
            <?php echo lang('edit_user_fname_label', 'first_name');?>
            <?php echo form_input($first_name);?>
      </p>

      <p>
            <?php echo lang('edit_user_lname_label', 'last_name');?>
            <?php echo form_input($last_name);?>
      </p>

      <p>
            <?php echo lang('forgot_password_username_identity_label', 'username');?>
            <?php echo form_input($username);?>
      </p>

      <p>
            <?php echo lang('create_user_email_label', 'email');?>
            <?php echo form_input($email);?>
      </p>

      <p>
            <?php echo lang('edit_user_company_label', 'company');?>
            <?php echo form_input($company);?>
      </p>

      <p>
            <?php echo lang('edit_user_phone_label', 'phone');?>
            <?php echo form_input($phone);?>
      </p>

      <p>
            <?php echo lang('edit_user_password_label', 'password');?>
            <?php echo form_input($password);?>
      </p>

      <p>
            <?php echo lang('edit_user_password_confirm_label', 'password_confirm');?>
            <?php echo form_input($password_confirm);?>
      </p>

