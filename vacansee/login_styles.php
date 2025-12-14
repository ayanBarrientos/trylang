/* Shared auth styles for VACANSEE (login & register) */

.auth-container,
.login-container,
.registration-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(190, 0, 2, 0.08), rgba(0, 39, 76, 0.05));
    padding: 2rem;
}

.auth-card,
.login-card,
.registration-card {
    background: var(--bg-white);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-hover);
    width: 100%;
    max-width: 520px;
    padding: 3rem;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--border-color);
}

.auth-card::before,
.login-card::before,
.registration-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
}

.auth-header,
.login-header,
.registration-header {
    text-align: center;
    margin-bottom: 2.5rem;
}

.auth-logo,
.login-logo,
.registration-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 15px;
    margin-bottom: 1rem;
}

.auth-logo img,
.login-logo img,
.registration-logo img {
    height: 50px;
    width: auto;
}

.auth-logo-text h1,
.login-logo-text h1,
.registration-logo-text h1 {
    font-size: 1.2rem;
    color: var(--secondary-color);
    margin: 0;
}

.auth-logo-text p,
.login-logo-text p,
.registration-logo-text p {
    font-size: 0.8rem;
    color: var(--primary-color);
    margin: 0;
}

.auth-title,
.login-title,
.registration-title {
    font-size: 1.9rem;
    color: var(--secondary-color);
    margin-bottom: 0.5rem;
}

.auth-subtitle,
.login-subtitle,
.registration-subtitle {
    color: var(--text-light);
    font-size: 0.95rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    color: var(--text-dark);
    font-weight: 500;
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid var(--border-color);
    border-radius: var(--border-radius);
    font-family: 'Poppins', sans-serif;
    font-size: 1rem;
    transition: var(--transition);
    background: var(--bg-white);
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(190, 0, 2, 0.12);
}

.password-container {
    position: relative;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-light);
    cursor: pointer;
    font-size: 1.2rem;
}

.alert {
    padding: 12px 15px;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-danger {
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.2);
    color: var(--danger-color);
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    border: 1px solid rgba(40, 167, 69, 0.2);
    color: var(--success-color);
}

.btn-primary,
.btn-login,
.btn-register {
    width: 100%;
    padding: 14px;
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: var(--border-radius);
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.btn-primary:hover,
.btn-login:hover,
.btn-register:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.auth-footer,
.login-footer,
.registration-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.auth-footer a,
.login-footer a,
.registration-footer a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: 500;
}

.auth-footer a:hover,
.login-footer a:hover,
.registration-footer a:hover {
    text-decoration: underline;
}

.user-type-selector {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
}

.user-type-btn {
    flex: 1;
    padding: 12px;
    border: 2px solid var(--border-color);
    background: var(--bg-light);
    border-radius: var(--border-radius);
    text-align: center;
    cursor: pointer;
    transition: var(--transition);
    font-weight: 500;
}

.user-type-btn:hover {
    border-color: var(--primary-light);
}

.user-type-btn.active {
    border-color: var(--primary-color);
    background: rgba(190, 0, 2, 0.1);
    color: var(--primary-color);
    font-weight: 600;
}

.user-type-btn i {
    display: block;
    font-size: 1.5rem;
    margin-bottom: 5px;
}

.password-requirements {
    font-size: 0.85rem;
    color: var(--text-light);
    margin-top: 0.5rem;
    padding-left: 10px;
}

.password-requirements ul {
    margin: 5px 0;
    padding-left: 20px;
}

.password-requirements li.valid {
    color: var(--success-color);
}

.password-requirements li.invalid {
    color: var(--text-light);
}

@media (max-width: 576px) {
    .auth-card,
    .login-card,
    .registration-card {
        padding: 2rem;
    }
}
