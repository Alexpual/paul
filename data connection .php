<?PHP
$firstname = $_Post['firstname'];
$lastname = $_Post['lastname'];
$phone= $_Post['phone'];
$email = $_Post['email'];
$password = $_Post['password'];
//data connection//
$conn = new mysqli('localhost','root','','signup')
if($conn->$connect_error){
die('connection failed..... : ' .$conn->connect_error)};
else{
$stmt=$conn->prepare("insert into registration(firstname,lastname,phone,email,password)
values=(?,?,?,?,?)");
$stmt->bind_param("ssiss",$firstname,$lastname,$phone,$email,$password);
$stmt->execute();
echo "submition successfully.....";
$stmt->close();
$conn->close();
}

? >