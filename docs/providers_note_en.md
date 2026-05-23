# ParsGreen

- This provider supports a dedicated API for sending OTP messages (SendOtp method).

- This provider supports sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider supports phonebook management (Groups: GroupAdd, GroupEdit, GroupDelete, GroupList / Contacts: ContactAdd, ContactList, ContactDelete, ContactCount).

- Authentication uses ApiKey header.

# SMS.ir

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead.

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider does NOT support phonebook management.


# Meli Payamak (ملی پیامک)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead.

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed in order only — key-value pairs are not accepted. The package will discard the keys and send the values in the order they were provided.

- This provider supports phonebook management (Groups: AddGroup, GetGroups / Contacts: AddContact, GetContacts, ChangeContact, RemoveContact, CheckMobileExist, GetContactEvents).

- This provider supports user management (AddUser, AddUserWithUserNameAndPass, ChangeUserCredit, AuthenticateUser, ForgotPassword, GetUserCredit, GetUserDetails).

- Authentication uses username and password (REST API) and SOAP for advanced features.


# Payam Resan (پیام رسان)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead.

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider accepts exactly 3 items as pattern variables.

- This provider does NOT support phonebook management (Based on available documentation).


# Kavenegar (کاوه نگار)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (verify/lookup).

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider does NOT support phonebook management.

- This provider supports blacklist management (blockedlist, blockedadd, blockedremove, blockedexists).


# Faraz SMS / iPanel (فراز اس ام اس)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead.

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider supports phonebook management (phone_book, phone_book_data, phone_book_attribute).

- This provider supports user management (/auth/login, /auth/register, /auth/verify-2fa).

- Authentication uses ApiKey header.


# Raygan SMS (ریگان اس ام اس / ترز رایان افزار)

- This provider supports a dedicated API for sending OTP messages (SendMessageWithCode.ashx and SendCode methods).

- For patterns, you need an access token (AccessHash) and a username and password; for other types, you need only a username and password.

- Pattern variables must be passed as key-value pairs.

- You CAN send one pattern to multiple phone numbers in a single API call.

- This provider does NOT support phonebook management.

- Authentication uses Basic Auth (username/password).


# WebOneSMS (وب وان اسمس)

- This provider supports a dedicated API for sending OTP messages (SmartOTP method).

- This provider does not offer a dedicated API for sending pattern messages. Use the simple text method instead.

- This provider supports limited phonebook (only ApplyContact for adding numbers to phonebook).

- This provider supports user management (CreateUser, ApplyManualCharge).

- Authentication uses X-API-KEY header.


# AmootSMS (آموت اس ام اس)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (SendWithPattern).

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed in order only — key-value pairs are not accepted. The package will discard the keys and send the values in the order they were provided.

- This provider supports phonebook management (ContactGroupList, ContactList, ContactCreate, ContactEdit, ContactDelete, ContactSearch, ContactChangeLabel).

- This provider supports user management (CreateUser, ApplyManualCharge).

- Authentication uses Token, UserName, Password.


# FaraPayamak (فراپیامک)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (BaseServiceNumber).

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed in order only — key-value pairs are not accepted. The package will discard the keys and send the values in the order they were provided.

- This provider supports phonebook management (AddGroup, GetGroups, AddContact, GetContacts, ChangeContact, RemoveContact, CheckMobileExist, GetContactEvents).

- This provider supports user management (AddUser, ChangeUserCredit, AuthenticateUser, GetUserCredit).

- Authentication uses username and password.


# Ghasedak (قاصدک)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (SendOtpSMS - but requires template).

- Pattern variables must be passed as key-value pairs.

- This provider does NOT support phonebook management (Phonebook features are commented in their documentation).

- This provider does NOT support blacklist.

- Authentication uses ApiKey header.


# BehinPayam (بهین پیام)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (SendTokenSingle).

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider accepts exactly 3 items as pattern variables.

- This provider does NOT support phonebook management.

- This provider supports blacklist (GetBlackList, AddToBlackList, RemoveFromBlackList).

- Authentication uses ApiKey as query parameter.


# Asanak (آسانک)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead (template).

- This provider doesn't support sending pattern to multiple phones at once.

- Pattern variables must be passed as key-value pairs.

- This provider does NOT support phonebook management.

- This provider supports blacklist (blacklist with actions: add, remove, list).

- Authentication uses username and password.


# Mediana (مدیانا / iPanel Edge)

- This provider does not offer a dedicated API for sending OTP messages. Use the pattern-based method instead.

- Pattern variables must be passed as key-value pairs.

- This provider supports phonebook management (/api/phonebooks, /api/phonebooks/numbers).

- This provider supports user management (/auth/login, /auth/register, /auth/verify-2fa).

- Multiple sending types supported: webservice, peer_to_peer, pattern, phonebook, keyword, postal_code, votp, file.

- Authentication uses Authorization header with token.
