-- SQLite table definitions for tarpit database

CREATE TABLE Issues (
    issID INT AUTO_INCREMENT,
    issTitle string,
    statusID int,
    issParent INT
);

CREATE TABLE IdMap (
    idmKey string,
    idmNum int,
    issID int
);

CREATE TABLE Stati (
    statusID INT AUTO_INCREMENT,
    statusValue string
);

CREATE TABLE Urls (
    urlID int,
    urlUrl string,
    urlInfo string,
    issID int
);

CREATE TABLE Branches (
    braID int,
    braName string,
    issID int
);

CREATE TABLE Events (
    evtID int,
    evtKey string,
    evtDate date,
    issID int,
);

CREATE TABLE Comments (
    comID int,
    comText string,
    issID int
);

