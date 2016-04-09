set search_path="supply";

create table "parts" (
  primary key ("supplier", "part_code")

  , "supplier" text
  , "part_code" text
);
