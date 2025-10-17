// Modal Elements
const editModal = document.getElementById('editModal');
const editUsername = document.getElementById('editUsername');
const editPassword = document.getElementById('editPassword');
const editAccessLevel = document.getElementById('editAccessLevel');

let editIndex = null;

// Open modal when Edit button is clicked
function openEditModal(index) {
  const user = users[index];
  editUsername.value = user.username;
  editPassword.value = user.password;
  editAccessLevel.value = user.accessLevel;
  editIndex = index;
  editModal.style.display = 'flex'; // Show modal
}

// Close modal
function closeModal() {
  editModal.style.display = 'none';
  editIndex = null;
}

// Save changes from modal
function saveEdit() {
  const username = editUsername.value.trim();
  const password = editPassword.value.trim();
  const accessLevel = editAccessLevel.value;

  if (!username || !password || !accessLevel) return alert("All fields required");
  if (username.length > 12 || password.length > 12) return alert("Max 12 characters");

  users[editIndex] = { username, password, accessLevel };
  renderUsers();   // Re-render the table
  closeModal();    // Close modal
}

// Update table to use modal for editing
function renderUsers() {
  usersTable.innerHTML = '';
  users.forEach((user, index) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${user.username}</td>
      <td>${user.password}</td>
      <td>${user.accessLevel}</td>
      <td>
        <button class="action-btn edit-btn" onclick="openEditModal(${index})">Edit</button>
        <button class="action-btn delete-btn" onclick="deleteUser(${index})">Delete</button>
      </td>`;
    usersTable.appendChild(tr);
  });
}
